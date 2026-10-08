@once
    @vite('themes/' . config('settings.theme') . '/js/easymde-entry.js', config('settings.theme'))
@endonce

@script
    <script>
        const initializeEditor = () => {
            const editor = new EasyMDE({
                element: document.getElementById('editor'),
                spellChecker: false,
                previewImagesInEditor: true,
                autoDownloadFontAwesome: false,
                status: [{
                    className: 'upload-image',
                    defaultValue: '',
                }],
                toolbar: [{
                        name: 'bold',
                        title: @js(__('Bold')),
                        action: EasyMDE.toggleBold,
                    }, {
                        name: 'italic',
                        title: @js(__('Italic')),
                        action: EasyMDE.toggleItalic,
                    }, {
                        name: 'strikethrough',
                        title: @js(__('Strikethrough')),
                        action: EasyMDE.toggleStrikethrough,
                    }, {
                        name: 'link',
                        title: @js(__('Link')),
                        action: EasyMDE.drawLink,
                    }, '|',
                    {
                        name: 'heading',
                        title: @js(__('Heading')),
                        action: EasyMDE.toggleHeadingSmaller,
                    }, '|',
                    {
                        name: 'quote',
                        title: @js(__('Quote')),
                        action: EasyMDE.toggleBlockquote,
                    }, {
                        name: 'code',
                        title: @js(__('Code block')),
                        action: EasyMDE.toggleCodeBlock,

                    }, {
                        name: 'unordered-list',
                        title: @js(__('Unordered list')),
                        action: EasyMDE.toggleUnorderedList,
                    }, {
                        name: 'ordered-list',
                        title: @js(__('Ordered list')),
                        action: EasyMDE.toggleOrderedList,
                    }, '|',
                    {
                        name: 'undo',
                        title: @js(__('Undo')),
                        action: EasyMDE.undo,
                    }, {
                        name: 'redo',
                        title: @js(__('Redo')),
                        action: EasyMDE.redo,
                    },

                ],
            });

            editor.codemirror.on('change', function() {
                @this.set('message', editor.value(), false);
            });

            // Listen for event called saved
            $wire.on('saved', () => {
                editor.clearAutosavedValue();
                editor.value('');
            });
        };

        if (window.EasyMDE) {
            initializeEditor();
        } else {
            document.addEventListener('easymde:ready', initializeEditor, { once: true });
        }
    </script>
@endscript
