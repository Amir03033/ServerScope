/* Applies the saved theme before the first paint (no flash). Loaded blocking in <head>. */
(function () {
    try {
        var t = localStorage.getItem('ss-theme');
        if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
    } catch (e) {}
})();
