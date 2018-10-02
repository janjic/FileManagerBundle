$(function () {

    // var $renameModal = $('#js-confirm-rename');
    var $deleteModal = $('#js-confirm-delete');
    var $connectedEntitiesModal         = $('#showConnectedEntitiesModal');
    var $connectedEntitiesModalButton   = $('.showConnectedEntitiesModalButton');
    var $connectModal                   = $('#findEntityModal');
    var $connectModalButton             = $('.findEntityModalButton');
    var $entitySelect                   = $('#entity');
    var $entityField                    = $('#entity-field');
    var $entitySearchValue              = $('#search-value');
    var locationPath                    = getQueryParameterByName('route');
    var classes,
        selectedClass,
        selectedImage           = null;

    var callback = function (key, opt) {
        switch (key) {
            // case 'edit':
            //     var $renameModalButton = opt.$trigger.find(".js-rename-modal")
            //     renameFile($renameModalButton)
            //     $renameModal.modal("show");
            //     break;
            case 'delete':
                var $deleteModalButton = opt.$trigger.find(".js-delete-modal")
                deleteFile($deleteModalButton)
                $deleteModal.modal("show");
                break;
            case 'download':
                var $downloadButton = opt.$trigger.find(".js-download")
                downloadFile($downloadButton)
                break;
        }
    };

    $.contextMenu({
        selector: '.file',
        callback: callback,
        items: {
            "delete": {name: deleteMessage, icon: "fa-trash"},
            // "edit": {name: renameMessage, icon: "fa-edit"},
            "download": {name: downloadMessage, icon: "fa-download"},
        }
    });
    $.contextMenu({
        selector: '.dir',
        callback: callback,
        items: {
            "delete": {name: deleteMessage, icon: "fa-trash"},
            // "edit": {name: renameMessage, icon: "fa-edit"},
        }
    });

    // function renameFile($renameModalButton) {
    //     $('#form_name').val($renameModalButton.data('name'));
    //     $('#form_old_name').val($renameModalButton.data('old_name'));
    //     $('#form_extension').val($renameModalButton.data('extension'));
    //     $renameModal.find('form').attr('action', $renameModalButton.data('href'))
    // }

    function deleteFile($deleteModalButton) {
        $('#js-confirm-delete').find('form').attr('action', $deleteModalButton.data('href'));
    }

    function downloadFile($downloadButton) {
        $downloadButton[0].click();
    }

    function initTree(treedata) {
        $('#tree').jstree({
            'core': {
                'data': treedata,
                "check_callback": true
            }
        }).bind("changed.jstree", function (e, data) {
            if (data.node) {
                document.location = data.node.a_attr.href;
            }
        });
    }

    if (tree === true) {

        // sticky kit
        $("#tree-block").stick_in_parent();

        initTree(treedata);
    }
    $(document)
    // checkbox select all
        .on('click', '#select-all', function () {
            var checkboxes = $('#form-multiple-delete').find(':checkbox')
            if ($(this).is(':checked')) {
                checkboxes.prop('checked', true);
            } else {
                checkboxes.prop('checked', false);
            }
        })
        // delete modal buttons
        .on('click', '.js-delete-modal', function () {
                deleteFile($(this));
            }
        )
        // rename modal buttons
        // .on('click', '.js-rename-modal', function () {
        //         renameFile($(this));
        //     }
        // )
        // multiple delete modal button
        .on('click', '#js-delete-multiple-modal', function () {
            var $multipleDelete = $('#form-multiple-delete').serialize();
            if ($multipleDelete) {
                var href = urldelete + '&' + $multipleDelete;
                $('#js-confirm-delete').find('form').attr('action', href);
            }
        })
        // checkbox
        .on('click', '#form-multiple-delete :checkbox', function () {
            var $jsDeleteMultipleModal = $('#js-delete-multiple-modal');
            if ($(".checkbox").is(':checked')) {
                $jsDeleteMultipleModal.removeClass('disabled');
            } else {
                $jsDeleteMultipleModal.addClass('disabled');
            }
        });

    // preselected
    // $renameModal.on('shown.bs.modal', function () {
    //     $('#form_name').select().mouseup(function () {
    //         $('#form_name').unbind("mouseup");
    //         return false;
    //     });
    // });
    $('#addFolder').on('shown.bs.modal', function () {
        $('#rename_name').select().mouseup(function () {
            $('#rename_name').unbind("mouseup");
            return false;
        });
    });


    // Module Tiny
    if (moduleName === 'tiny') {

        $('#form-multiple-delete').on('click', '.select', function () {
            var args = top.tinymce.activeEditor.windowManager.getParams();
            var input = args.input;
            var document = args.window.document;
            var divInputSplit = document.getElementById(input).parentNode.id.split("_");

            // set url
            document.getElementById(input).value = $(this).attr("data-path");

            // set width and height
            var baseId = divInputSplit[0] + '_';
            var baseInt = parseInt(divInputSplit[1], 10);

            divWidth = baseId + (baseInt + 3);
            divHeight = baseId + (baseInt + 5);

            document.getElementById(divWidth).value = $(this).attr("data-width");
            document.getElementById(divHeight).value = $(this).attr("data-height");

            top.tinymce.activeEditor.windowManager.close();
        });
    }

    // Global functions
    // display error alert
    function displayError(msg) {
        displayAlert('danger', msg)
    }

    // display success alert
    function displaySuccess(msg) {
        displayAlert('success', msg)
    }

    // file upload
    $('#fileupload').fileupload({
        dataType: 'json',
        processQueue: false,
        dropZone: $('#dropzone')
    }).on('fileuploaddone', function (e, data) {
        $.each(data.result.files, function (index, file) {
            if (file.url) {
                displaySuccess('<strong>' + file.name + '</strong> ' + successMessage)
                // Ajax update view
                $.ajax({
                    dataType: "json",
                    url: url,
                    type: 'GET'
                }).done(function (data) {
                    // update file list
                    $('#form-multiple-delete').html(data.data);
                    if (tree === true) {
                        $('#tree').data('jstree', false).empty();
                        initTree(data.treeData);
                    }

                    $('#select-all').prop('checked', false);
                    $('#js-delete-multiple-modal').addClass('disabled');

                }).fail(function (jqXHR, textStatus, errorThrown) {
                    displayError('<strong>Ajax call error :</strong> ' + jqXHR.status + ' ' + errorThrown)
                });

            } else if (file.error) {
                displayError('<strong>' + file.name + '</strong> ' + file.error)
            }
        });
    }).on('fileuploadfail', function (e, data) {
        $.each(data.files, function (index, file) {
            displayError('File upload failed.')
        });
    });

    $connectedEntitiesModalButton.on('click', function (event) {
        event.preventDefault();
        let location = $(this).data('location');
        let img      = $(this).data('name');
        function callbackFunction(ctx, data) {
            if (data.status === 200) {
                if (data.rows.length) {
                    let rows = [];
                    data.rows.forEach(function (el) {
                        let row     = document.createElement('tr');
                        let entity  = document.createElement('td');
                        entity.appendChild(document.createTextNode(el.type));
                        let title   = document.createElement('td');
                        title.appendChild(document.createTextNode(el.title));
                        row.appendChild(entity);
                        row.appendChild(title);
                        rows.push(row);
                    });
                    let bodyContent = $connectedEntitiesModal.find('#connected-entities-content');
                    rows.forEach(function (el) {
                        bodyContent.append(el);
                    });

                    $connectedEntitiesModal.find('.modal-title').html(img);
                    $connectedEntitiesModal.modal();
                } else {
                    toastr.warning(Translator.trans(data.message));
                }
            } else {
                toastr.error(Translator.trans(data.message));
            }
        }

        adapter.sendData(Routing.generate('file_manager_find_related_entities', {_locale: getURLParameter('locale')}, true), { 'name': img, location: location }, 'POST', callbackFunction, this);
    });


    $connectModalButton.on('click', function (event) {
        event.preventDefault();
        selectedImage = $(this).data('name');
        function callbackFunction(ctx, data) {
            classes = data;
            for (const [key, value] of Object.entries(classes)) {
                $entitySelect.append(new Option(value.name, key));
            }
            initializeEntitySelect();

            $connectModal.modal();
        }

        /** Don't send request for loading classes if they are already loaded */
        if (classes) {
            initializeEntitySelect();
            $connectModal.modal();

            return;
        }

        adapter.getData(Routing.generate('file_manager_load_classes_json', {_locale: getURLParameter('locale')}, true), callbackFunction, "");
    });

    $connectedEntitiesModal.on('hidden.bs.modal', function () {
        $connectedEntitiesModal.find('#connected-entities-content').empty();
    });

    $connectModal.on('hidden.bs.modal', function () {
        selectedClass = selectedImage = null;
        if ($entitySelect.data('select2')) {
            $entitySelect.unbind('select2:select');
            $entitySelect.select2('destroy');
        }
        if ($entityField.data('select2')) {
            $entityField.empty();
            $entityField.unbind('select2:select');
            $entityField.select2('destroy');
        }
        if ($entitySearchValue.data('select2')) {
            $entitySearchValue.empty();
            $entitySearchValue.unbind('select2:select');
            $entitySearchValue.select2('destroy');
        }
        hideSelects();
    });

    function hideSelects() {
        $entityField.closest('.form-group').hide();
        $entitySearchValue.closest('.form-group').hide();
    }

    function initializeEntitySelect() {
        $entitySelect.val([]);
        $entitySelect.select2({
            dropdownParent: $("#findEntityModal"),
            placeholder: Translator.trans('Select entity')
        }).on('select2:select', function(element) {
            let selected = element.params.data.id;
            for (const [key, value] of Object.entries(classes)) {
                if (selected === key) {
                    selectedClass = value;
                    if ($entityField.data('select2')) {
                        $entityField.select2('destroy');
                    }
                    $entityField.empty();

                    value.mappedFields.forEach(function (el) {
                        $entityField.append(new Option(el, el));
                    });

                    $entityField.val([]);
                    $entityField.select2({
                        dropdownParent: $("#findEntityModal"),
                        placeholder: Translator.trans('Select field')
                    }).on('select2:select', function () {
                        initSearch();
                        $entitySearchValue.closest('.form-group').show();
                    });
                    $entityField.closest('.form-group').show();

                    return true;
                }
            }
        });
    }

    function initSearch() {
        $entitySearchValue.select2({
            dropdownParent: $("#findEntityModal"),
            placeholder: Translator.trans('Search main entity'),
            minimumInputLength: 1,
            dataType: 'json',
            ajax: {
                url: Routing.generate('file_manager_entity_search', {_locale: getURLParameter('locale') }, true),
                delay: 250,
                method: "POST",
                data: function (params) {
                    return JSON.stringify({
                        entity: selectedClass.path,
                        fields: selectedClass.searchFields,
                        view: selectedClass.viewField,
                        query: params.term,
                        page: params.page,
                        offset: 10
                    });
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;

                    return {
                        results: data.results,
                        pagination: {
                            more: true
                        }
                    };
                }
            },
            escapeMarkup: function (markup) {
                return markup;
            },
            templateResult: function (item) {
                if (item.loading) return Translator.trans('Loading') + '...';

                item.text = item.title;

                return '<div class="select2-result clearfix" data-id="' + item.id + '" data-title="' + item.id + '">' + item.title + '</div>';
            }
        }).on('select2:select', function(e) {
            let element = e.params.data;
            let obj = {
                entity: selectedClass.path,
                entityId: element.id,
                field: $entityField.val(),
                fileName: selectedImage,
                filePath: locationPath
            };
            function successFunction(ctx, data) {
                if (data.status === 200) {
                    toastr.success(Translator.trans(data.message));
                } else {
                    toastr.error(Translator.trans(data.message));
                }
                $connectModal.modal('hide');
            }

            adapter.sendData(Routing.generate('file_manager_assign_file_json', {_locale: getURLParameter('locale')}, true), obj, 'POST', successFunction, $(this));
        });
    }
})
;
