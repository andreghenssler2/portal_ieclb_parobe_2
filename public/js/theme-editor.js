(() => {
    'use strict';
    const editor = document.getElementById('themeCodeEditor');
    const form = document.getElementById('themeEditorForm');
    if (!editor || !form) return;

    const initial = editor.value;
    let submitted = false;

    editor.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') return;
        event.preventDefault();
        const start = editor.selectionStart;
        const end = editor.selectionEnd;
        editor.setRangeText('    ', start, end, 'end');
    });

    form.addEventListener('submit', () => { submitted = true; });
    window.addEventListener('beforeunload', (event) => {
        if (submitted || editor.value === initial) return;
        event.preventDefault();
        event.returnValue = '';
    });
})();
