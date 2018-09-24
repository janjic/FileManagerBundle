<?php
namespace Artgris\Bundle\FileManagerBundle\Controller;

use Alchemy\Zippy\Zippy;
use Artgris\Bundle\FileManagerBundle\Event\FileManagerEvents;
use Artgris\Bundle\FileManagerBundle\Helpers\File;
use Artgris\Bundle\FileManagerBundle\Helpers\FileManager;
use Artgris\Bundle\FileManagerBundle\Helpers\UploadHandler;
use Artgris\Bundle\FileManagerBundle\Twig\OrderExtension;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @author Arthur Gribet <a.gribet@gmail.com>
 */
class ManagerController extends Controller
{
    /**
     * @var FileManager
     */
    protected $fileManager;

    /**
     * @Route("/{_locale}/backend/file-manager", name="file_manager", defaults={"_locale": "en"}, options={"expose" = true})
     *
     * @param Request $request
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function indexAction(Request $request)
    {
        $queryParameters = array_merge($request->query->all(), ['_locale' => $request->get('_locale')]);
        $translator = $this->get('translator');
        $isJson = $request->get('json') ? true : false;
        if ($isJson) {
            unset($queryParameters['json']);
        }
        $fileManager = $this->newFileManager($queryParameters);

        // Folder search
        $directoriesArbo = $this->retrieveSubDirectories($fileManager, $fileManager->getDirName(), DIRECTORY_SEPARATOR, $fileManager->getBaseName());

        // File search
        $finderFiles = new Finder();
        $finderFiles->in($fileManager->getCurrentPath())->depth(0);
        $regex = $fileManager->getRegex();

        $orderBy = $fileManager->getQueryParameter('orderby');
        $orderDESC = OrderExtension::DESC === $fileManager->getQueryParameter('order');
        if (!$orderBy) {
            $finderFiles->sortByType();
        }

        switch ($orderBy) {
            case 'name':
                $finderFiles->sort(function (SplFileInfo $a, SplFileInfo $b) {
                    return strcmp(strtolower($b->getFilename()), strtolower($a->getFilename()));
                });
                break;
            case 'date':
                $finderFiles->sortByModifiedTime();
                break;
            case 'size':
                $finderFiles->sort(function (\SplFileInfo $a, \SplFileInfo $b) {
                    return $a->getSize() - $b->getSize();
                });
                break;
        }

        if ($fileManager->getTree()) {
            $finderFiles->files()->name($regex)->filter(function (SplFileInfo $file) {
                return $file->isReadable();
            });
        } else {
            $finderFiles->filter(function (SplFileInfo $file) use ($regex) {
                if ('file' === $file->getType()) {
                    if (preg_match($regex, $file->getFilename())) {
                        return $file->isReadable();
                    }

                    return false;
                }

                return $file->isReadable();
            });
        }

        $formDelete = $this->createDeleteForm()->createView();
        $fileArray = [];
        foreach ($finderFiles as $file) {
            $fileArray[] = new File($file, $this->get('translator'), $this->get('file_type_service'), $fileManager);
        }

        if ('dimension' === $orderBy) {
            usort($fileArray, function (File $a, File $b) {
                $aDimension = $a->getDimension();
                $bDimension = $b->getDimension();
                if ($aDimension && !$bDimension) {
                    return 1;
                }

                if (!$aDimension && $bDimension) {
                    return -1;
                }

                if (!$aDimension && !$bDimension) {
                    return 0;
                }

                return ($aDimension[0] * $aDimension[1]) - ($bDimension[0] * $bDimension[1]);
            });
        }

        if ($orderDESC) {
            $fileArray = array_reverse($fileArray);
        }

        $parameters = [
            'fileManager' => $fileManager,
            'fileArray' => $fileArray,
            'formDelete' => $formDelete,
        ];

        if ($isJson) {
            $fileList = $this->renderView('@ArtgrisFileManager/views/_manager_view.html.twig', $parameters);

            return new JsonResponse(['data' => $fileList, 'badge' => $finderFiles->count(), 'treeData' => $directoriesArbo]);
        }
        $parameters['treeData'] = json_encode($directoriesArbo);

        $form = $this->get('form.factory')->createNamedBuilder('rename', FormType::class)
            ->add('name', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                ],
                'label' => false,
                'data' => $translator->trans('input.default'),
            ])
            ->add('send', SubmitType::class, [
                'attr' => [
                    'class' => 'btn btn-primary',
                ],
                'label' => $translator->trans('button.save'),
            ])
            ->getForm();

        /* @var Form $form */
        $form->handleRequest($request);
        /** @var Form $formRename */
        $formRename = $this->createRenameForm();

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $fs = new Filesystem();
            $directory = $directorytmp = $fileManager->getCurrentPath().DIRECTORY_SEPARATOR.$data['name'];
            $i = 1;

            while ($fs->exists($directorytmp)) {
                $directorytmp = "{$directory} ({$i})";
                ++$i;
            }
            $directory = $directorytmp;

            try {
                $fs->mkdir($directory);
                $this->addFlash('success', $translator->trans('folder.add.success'));
            } catch (IOExceptionInterface $e) {
                $this->addFlash('danger', $translator->trans('folder.add.danger', ['%message%' => $data['name']]));
            }

            return $this->redirectToRoute('file_manager', $fileManager->getQueryParameters());
        }
        $parameters['form'] = $form->createView();
        $parameters['formRename'] = $formRename->createView();

        return $this->render('@ArtgrisFileManager/manager.html.twig', $parameters);
    }

    /**
     * @Route("/{_locale}/backend/file/manager/rename", name="file_manager_rename", defaults={"_locale": "en"}, options={"expose" = true})
     *
     * @param Request $request
     * @param $fileName
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @throws \Exception
     */
    public function renameFileAction(Request $request)
    {
        $translator = $this->get('translator');
        $queryParameters = array_merge($request->query->all(), ['_locale' => $request->get('_locale')]);
        $formRename = $this->createRenameForm();
        /* @var Form $formRename */
        $formRename->handleRequest($request);
        if ($formRename->isSubmitted() && $formRename->isValid()) {
            $data = $formRename->getData();
            $extension = $data['extension'] ? '.'.$data['extension'] : '';
            $fileName = $data['old_name'].$extension;
            $newfileName = $data['name'].$extension;
            if ($newfileName !== $fileName && isset($data['name'])) {
                $fileManager = $this->newFileManager($queryParameters);
                $NewfilePath = $fileManager->getCurrentPath().DIRECTORY_SEPARATOR.$newfileName;
                $OldfilePath = realpath($fileManager->getCurrentPath().DIRECTORY_SEPARATOR.$fileName);
                if (0 !== strpos($NewfilePath, $fileManager->getCurrentPath())) {
                    $this->addFlash('danger', $translator->trans('file.renamed.unauthorized'));
                } else {
                    $fs = new Filesystem();
                    try {
                        $fs->rename($OldfilePath, $NewfilePath);
                        $this->addFlash('success', $translator->trans('file.renamed.success'));
                        $this->dispatch(FileManagerEvents::POST_FILE_RENAME, ['oldFileName' => $fileName, 'newFileName' => $newfileName]);
                        //File has been renamed successfully
                    } catch (IOException $exception) {
                        $this->addFlash('danger', $translator->trans('file.renamed.danger'));
                    }
                }
            } else {
                $this->addFlash('warning', $translator->trans('file.renamed.nochanged'));
            }
        }

        return $this->redirectToRoute('file_manager', $queryParameters);
    }

    /**
     *
     * Logging operation - to a file (upload_log.txt) and to the stdout
     * @param string $str - the logging string
     */
    public function log($str)
    {
        // log to the output
        $logStr = date('d.m.Y').": {$str}\r\n";
        echo $logStr;

        // log to file
        if (($fp = fopen('upload_log.txt', 'a+')) !== false) {
            fwrite($fp, $logStr);
            fclose($fp);
        }
    }

    /**
     *
     * Delete a directory RECURSIVELY
     * @param string $dir - directory path
     *
     * @link http://php.net/manual/en/function.rmdir.php
     */
    public function rrmdir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ('.' !== $object && '..' !== $object) {
                    if (filetype($dir.'/'.$object) === 'dir') {
                        $this->rrmdir($dir.'/'.$object);
                    } else {
                        unlink($dir.'/'.$object);
                    }
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }

    /**
     * Check if all the parts exist, and gather all the parts of the file together
     * @param string $tempDir    - the temporary directory holding all the parts of the file
     * @param string $fileName   - the original file name
     * @param string $chunkSize  - each chunk size (in bytes)
     * @param string $totalSize  - original file size (in bytes)
     * @param string $totalFiles - original file size (in bytes)
     *
     * @return bool
     */
    public function createFileFromChunks($tempDir, $fileName, $chunkSize, $totalSize, $totalFiles)
    {

        // count all the parts of this file
        $totalFilesOnServerSize = 0;
        $tempTotal = 0;
        foreach (scandir($tempDir) as $file) {
            $tempTotal = $totalFilesOnServerSize;
            $tempfilesize = filesize($tempDir.'/'.$file);
            $totalFilesOnServerSize = $tempTotal + $tempfilesize;
        }
        // check that all the parts are present
        // If the Size of all the chunks on the server is equal to the size of the file uploaded.
        if ($totalFilesOnServerSize >= $totalSize) {
            // create the final destination file
            if (($fp = fopen($tempDir.'/'.$fileName, 'w')) !== false) {
                for ($i = 1; $i <= $totalFiles; $i++) {
                    fwrite($fp, file_get_contents($tempDir.'/'.$fileName.'.part'.$i));
                    $this->log('writing chunk '.$i);
                }
                fclose($fp);
            } else {
                $this->log('cannot create the destination file');

                return false;
            }

            // rename the temporary directory (to avoid access from other
            // concurrent chunks uploads) and than delete it
            if (rename($tempDir, $tempDir.'_UNUSED')) {
                $this->rrmdir($tempDir.'_UNUSED');
            } else {
                $this->rrmdir($tempDir);
            }
        }
    }


    /**
     * @Route("/{_locale}/backend/file/manager/archive-upload/", name="file_manager_upload_archive", defaults={"_locale": "en"}, options={"expose" = true})
     *
     * @param Request $request
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function uploadArchiveFileAction(Request $request)
    {

        if ('GET' === $_SERVER['REQUEST_METHOD']) {
            if (!(isset($_GET['resumableIdentifier']) && '' !== trim($_GET['resumableIdentifier']))) {
                $_GET['resumableIdentifier'] = '';
            }
            $tempDir = 'temp/'.$_GET['resumableIdentifier'];
            if (!(isset($_GET['resumableFilename']) && '' !== trim($_GET['resumableFilename']))) {
                $_GET['resumableFilename'] = '';
            }
            if (!(isset($_GET['resumableChunkNumber']) && '' !== trim($_GET['resumableChunkNumber']))) {
                $_GET['resumableChunkNumber'] = '';
            }
            $chunkFile = $tempDir.'/'.$_GET['resumableFilename'].'.part'.$_GET['resumableChunkNumber'];
            if (file_exists($chunkFile)) {
                header('HTTP/1.0 200 Ok');
            } else {
                header('HTTP/1.0 404 Not Found');
            }
        }

// loop through files and move the chunks to a temporarily created directory
        if (!empty($_FILES)) {
            foreach ($_FILES as $file) {
                // check the error status
                if ((int) $file['error'] !== 0) {
                    $this->log('error '.$file['error'].' in file '.$_POST['resumableFilename']);
                    continue;
                }

                // init the destination file (format <filename.ext>.part<#chunk>
                // the file is stored in a temporary directory
                if (isset($_POST['resumableIdentifier']) && '' !== trim($_POST['resumableIdentifier'])) {
                    $tempDir = 'temp/'.$_POST['resumableIdentifier'];
                }
                $destFile = $tempDir.'/'.$_POST['resumableFilename'].'.part'.$_POST['resumableChunkNumber'];

                // create the temporary directory
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0777, true);
                }

                // move the temporary file
                if (!move_uploaded_file($file['tmp_name'], $destFile)) {
                    $this->log('Error saving (move_uploaded_file) chunk '.$_POST['resumableChunkNumber'].' for file '.$_POST['resumableFilename']);
                } else {
                    // check if all the parts present, and create the final destination file
                    $this->createFileFromChunks($tempDir, $_POST['resumableFilename'], $_POST['resumableChunkSize'], $_POST['resumableTotalSize'], $_POST['resumableTotalChunks']);
                }
            }
        }


        return new JsonResponse('ok');










        $fileManager = $this->newFileManager($request->query->all());

        $options = [
            'upload_dir' => $fileManager->getCurrentPath().DIRECTORY_SEPARATOR,
            'upload_url' => $fileManager->getImagePath(),
            'accept_file_types' => $fileManager->getRegex(),
            'print_response' => false,
        ];
        if (isset($fileManager->getConfiguration()['upload'])) {
            $options += $fileManager->getConfiguration()['upload'];
        }
        $uploadHandler = new UploadHandler($options);
        $response = $uploadHandler->response;

        return new JsonResponse('ok');



//        $fileManager = $this->newFileManager($request->query->all());
//        $targetDir = sprintf('%s%s', $fileManager->getBasePath(), (isset($_REQUEST['identity']) ? DIRECTORY_SEPARATOR.$_REQUEST['identity'] : ''));
        $targetDir = '/var/www/instances/fwebshop.home/web/uploads/test';

        $cleanupTargetDir = false; // Remove old files
        $maxFileAge = 5 * 3600; // Temp file age in seconds
        // Create target dir
        if (!file_exists($targetDir)) {
            @mkdir($targetDir);
        }
        // Get a file name
        if (isset($_REQUEST['name'])) {
            $fileName = $_REQUEST['name'];
        } elseif (!empty($_FILES)) {
            $fileName = $_FILES['file']['name'];
        } else {
            $fileName = uniqid('file_');
        }
        $filePath = $targetDir.DIRECTORY_SEPARATOR.$fileName;
        // Chunking might be enabled
        $chunk = isset($_REQUEST['chunk']) ? (int) $_REQUEST['chunk'] : 0;
        $chunks = isset($_REQUEST['chunks']) ? (int) $_REQUEST['chunks'] : 0;
        // Remove old temp files
        if ($cleanupTargetDir) {
            if (!is_dir($targetDir) || !$dir = opendir($targetDir)) {
                return new JsonResponse(['error'   => ['message' => 'Failed to open temp directory.', 'id' => 'id']], 100);
            }
            while (($file = readdir($dir)) !== false) {
                $tmpfilePath = $targetDir.DIRECTORY_SEPARATOR.$file;
                // If temp file is current file proceed to the next
                if ("{$filePath}.part" === $tmpfilePath) {
                    continue;
                }
                // Remove temp file if it is older than the max age and is not the current file
                if (preg_match('/\.part$/', $file) && (filemtime($tmpfilePath) < time() - $maxFileAge)) {
                    @unlink($tmpfilePath);
                }
            }
            closedir($dir);
        }
        // Open temp file
        if (!$out = fopen("{$filePath}.part", $chunks ? 'ab' : 'wb')) {
            return new JsonResponse(['error'   => ['message' => 'Failed to open output stream.', 'id' => 'id']], 102);
        }

        if (!empty($_FILES)) {
            if ($_FILES['file']['error'] || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                return new JsonResponse(['error'   => ['message' => 'Failed to move uploaded file.', 'id' => 'id']], 103);
            }
            // Read binary input stream and append it to temp file
            if (!$in = fopen($_FILES['file']['tmp_name'], 'rb')) {
                return new JsonResponse(['error'   => ['message' => 'Failed to open input stream.', 'id' => 'id']], 101);
            }
        } elseif (!empty($_REQUEST['file'])) {
            // Read binary input stream and append it to temp file
            if (!$in = fopen($_REQUEST['file'], 'rb')) {
                return new JsonResponse(['error'   => ['message' => 'Failed to open input stream.', 'id' => 'id']], 101);
            }
        } elseif (!$in = fopen('php://input', 'rb')) {
            return new JsonResponse(['error'   => ['message' => 'Failed to open input stream.', 'id' => 'id']], 101);
        }

        while ($buff = fread($in, 4096)) {
            fwrite($out, $buff);
        }
        fclose($out);
        fclose($in);
        // Check if file has been uploaded
        if (!$chunks || $chunk ===  $chunks - 1) {
            // Strip the temp .part suffix off
            rename("{$filePath}.part", $filePath);

            try {
//                $moveToDir = $fileManager->getCurrentPath();
                $zip = new \ZipArchive();
//                $zippy = Zippy::load();
                $res = $zip->open($filePath);
                $extractDir = $targetDir.DIRECTORY_SEPARATOR.'tmp';
                if (!file_exists($targetDir)) {
                    @mkdir($targetDir);
                }
//                $archive = $zippy->open($filePath);
//                $archive->extract($targetDir);
                $result = $zip->extractTo($targetDir);
                $zip->close();
            } catch (\Exception $e) {
                return new JsonResponse(['error'   => ['message' => 'Failed to open uploaded file.', 'id' => 'id']], 104);
            }
        }

        return new JsonResponse(['result' => null, 'id' => 'id']);
    }


    /**
     * @Route("/{_locale}/backend/file/manager/upload/", name="file_manager_upload", defaults={"_locale": "en"}, options={"expose" = true})
     *
     * @param Request $request
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function uploadFileAction(Request $request)
    {
        $fileManager = $this->newFileManager($request->query->all());

        $options = [
            'upload_dir' => $fileManager->getCurrentPath().DIRECTORY_SEPARATOR,
            'upload_url' => $fileManager->getImagePath(),
            'accept_file_types' => $fileManager->getRegex(),
            'print_response' => false,
        ];
        if (isset($fileManager->getConfiguration()['upload'])) {
            $options += $fileManager->getConfiguration()['upload'];
        }

        $this->dispatch(FileManagerEvents::PRE_UPDATE, ['options' => &$options]);

        $uploadHandler = new UploadHandler($options);
        $response = $uploadHandler->response;

        foreach ($response['files'] as $file) {
            if (isset($file->error)) {
                $file->error = $this->get('translator')->trans($file->error);
            }

            if (!$fileManager->getImagePath()) {
                $file->url = $this->generateUrl('file_manager_file', array_merge($fileManager->getQueryParameters(), ['fileName' => $file->url], ['_locale' => $request->get('_locale')]));
            }
        }

        $this->dispatch(FileManagerEvents::POST_UPDATE, ['response' => &$response]);

        return new JsonResponse($response);
    }

    /**
     * @Route("/{_locale}/backend/file/manager/file/{fileName}", name="file_manager_file", defaults={"_locale": "en"}, options={"expose" = true})
     *
     * @param Request $request
     * @param $fileName
     *
     * @return BinaryFileResponse
     *
     * @throws \Exception
     */
    public function binaryFileResponseAction(Request $request, $fileName)
    {
        $fileManager = $this->newFileManager($request->query->all());

        return new BinaryFileResponse($fileManager->getCurrentPath().DIRECTORY_SEPARATOR.urldecode($fileName));
    }

    /**
     * @Route("/{_locale}/backend/file/manager/delete/", name="file_manager_delete", defaults={"_locale": "en"}, options={"expose" = true})
     *
     * @param Request $request
     * @Method("DELETE")
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @throws \Exception
     */
    public function deleteAction(Request $request)
    {
        $form = $this->createDeleteForm();
        $form->handleRequest($request);
        $queryParameters = array_merge($request->query->all(), ['_locale' => $request->get('_locale')]);
        if ($form->isSubmitted() && $form->isValid()) {
            // remove file
            $fileManager = $this->newFileManager($queryParameters);
            $fs = new Filesystem();
            if (isset($queryParameters['delete'])) {
                $isDelete = false;
                foreach ($queryParameters['delete'] as $fileName) {
                    $fileDeleted = true;
                    $filePath = realpath($fileManager->getCurrentPath().DIRECTORY_SEPARATOR.$fileName);
                    $fileInfo = ['fileName' => $fileName];
                    if (0 !== strpos($filePath, $fileManager->getCurrentPath())) {
                        $this->addFlash('danger', 'file.deleted.danger');
                    } else {
                        $this->dispatch(FileManagerEvents::PRE_DELETE_FILE, $fileInfo);
                        try {
                            $fs->remove($filePath);
                            $isDelete = true;
                        } catch (IOException $exception) {
                            $this->addFlash('danger', 'file.deleted.unauthorized');
                            $fileDeleted = false;
                        }
                        if ($fileDeleted) {
                            $this->dispatch(FileManagerEvents::POST_DELETE_FILE, $fileInfo);
                        }
                    }
                }
                $this->dispatch(FileManagerEvents::POST_DELETE_FILE_DONE, $fileInfo);
                if ($isDelete) {
                    $this->addFlash('success', 'file.deleted.success');
                }
                unset($queryParameters['delete']);
            } else {
                $this->dispatch(FileManagerEvents::PRE_DELETE_FOLDER);
                try {
                    $fs->remove($fileManager->getCurrentPath());
                    $this->addFlash('success', 'folder.deleted.success');
                } catch (IOException $exception) {
                    $this->addFlash('danger', 'folder.deleted.unauthorized');
                }

                $this->dispatch(FileManagerEvents::POST_DELETE_FOLDER);
                $queryParameters['route'] = dirname($fileManager->getCurrentRoute());
                if ($queryParameters['route'] = '/') {
                    unset($queryParameters['route']);
                }

                return $this->redirectToRoute('file_manager', $queryParameters);
            }
        }

        return $this->redirectToRoute('file_manager', $queryParameters);
    }

    /**
     * @return Form|\Symfony\Component\Form\FormInterface
     */
    private function createDeleteForm()
    {
        return $this->createFormBuilder()
            ->setMethod('DELETE')
            ->add('DELETE', SubmitType::class, [
                'attr' => [
                    'class' => 'btn btn-danger',
                ],
                'label' => 'button.delete.action',
            ])
            ->getForm();
    }

    /**
     * @return mixed
     */
    private function createRenameForm()
    {
        return $this->createFormBuilder()
            ->add('name', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                ],
                'label' => false,
            ])
            ->add('old_name', HiddenType::class)
            ->add('extension', HiddenType::class)
            ->add('send', SubmitType::class, [
                'attr' => [
                    'class' => 'btn btn-primary',
                ],
                'label' => 'button.rename.action',
            ])
            ->getForm();
    }

    /**
     * @param FileManager $fileManager
     * @param $path
     * @param string $parent
     * @param bool   $baseFolderName
     *
     * @return array|null
     */
    private function retrieveSubDirectories(FileManager $fileManager, $path, $parent = DIRECTORY_SEPARATOR, $baseFolderName = false)
    {
        $directories = new Finder();
        $directories->in($path)->ignoreUnreadableDirs()->directories()->depth(0)->sortByType()->filter(function (SplFileInfo $file) {
            return $file->isReadable();
        });

        if ($baseFolderName) {
            $directories->name($baseFolderName);
        }
        $directoriesList = null;

        foreach ($directories as $directory) {
            /** @var SplFileInfo $directory */
            $fileName = $baseFolderName ? '' : $parent.$directory->getFilename();

            $queryParameters = $fileManager->getQueryParameters();
            $queryParameters['route'] = $fileName;
            $queryParametersRoute = $queryParameters;
            unset($queryParametersRoute['route']);

            $filesNumber = $this->retrieveFilesNumber($directory->getPathname(), $fileManager->getRegex());
            $fileSpan = $filesNumber > 0 ? " <span class='label label-default'>{$filesNumber}</span>" : '';

            $directoriesList[] = [
                'text' => $directory->getFilename().$fileSpan,
                'icon' => 'fa fa-folder-o',
                'children' => $this->retrieveSubDirectories($fileManager, $directory->getPathname(), $fileName.DIRECTORY_SEPARATOR),
                'a_attr' => [
                    'href' => $fileName ? $this->generateUrl('file_manager', $queryParameters) : $this->generateUrl('file_manager', $queryParametersRoute),
                ], 'state' => [
                    'selected' => $fileManager->getCurrentRoute() === $fileName,
                    'opened' => true,
                ],
            ];
        }

        return $directoriesList;
    }

    /**
     * Tree Iterator.
     *
     * @param $path
     * @param $regex
     *
     * @return int
     */
    private function retrieveFilesNumber($path, $regex)
    {
        $files = new Finder();
        $files->in($path)->files()->depth(0)->name($regex);

        return iterator_count($files);
    }

    /*
     * Base Path
     */
    private function getBasePath($queryParameters)
    {
        $conf = $queryParameters['conf'];
        $managerConf = $this->getParameter('artgris_file_manager')['conf'];
        if (isset($managerConf[$conf]['dir'])) {
            return $managerConf[$conf];
        }

        if (isset($managerConf[$conf]['service'])) {
            $extra = isset($queryParameters['extra']) ? $queryParameters['extra'] : [];
            $conf = $this->get($managerConf[$conf]['service'])->getConf($extra);

            return $conf;
        }

        throw new \RuntimeException('Please define a "dir" or a "service" parameter in your config.yml');
    }

    /**
     * @return mixed
     */
    private function getKernelRoute()
    {
        return $this->getParameter('kernel.root_dir');
    }

    /**
     * @param $queryParameters
     *
     * @return FileManager
     *
     * @throws \Exception
     */
    private function newFileManager($queryParameters)
    {
        if (!isset($queryParameters['conf'])) {
            $queryParameters['conf'] = 'default';
        }
        $webDir = $this->getParameter('artgris_file_manager')['web_dir'];

        $this->fileManager = new FileManager($queryParameters, $this->getBasePath($queryParameters), $this->getKernelRoute(), $this->get('router'), $webDir);

        return $this->fileManager;
    }

    protected function dispatch($eventName, array $arguments = [])
    {
        $arguments = array_replace([
            'filemanager' => $this->fileManager,
        ], $arguments);

        $subject = $arguments['filemanager'];
        $event = new GenericEvent($subject, $arguments);
        $this->get('event_dispatcher')->dispatch($eventName, $event);
    }
}
