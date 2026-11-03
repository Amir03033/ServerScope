/* Applies the saved theme before the first paint (no flash). Loaded blocking in <head>. */
(function () {
    try {
        var t = localStorage.getItem('ss-theme');
        if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
        // Folded menu parts (NEXUS): open or closed as last chosen in this browser.
        var n = localStorage.getItem('ss-nav-nexus');
        if (n === 'open' || n === 'closed') document.documentElement.setAttribute('data-nav-nexus', n);
    } catch (e) {}
})();
