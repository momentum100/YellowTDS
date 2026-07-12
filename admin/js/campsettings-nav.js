export function showSection(targetId) {
    document.querySelectorAll('.camp-section').forEach(function (s) {
        s.classList.toggle('active', s.id === targetId);
    });
    document.querySelectorAll('.camp-sidebar a').forEach(function (link) {
        link.classList.toggle('active', link.getAttribute('href') === '#' + targetId);
    });
}

// Window export for backward compat
window.showSection = showSection;

document.querySelector('.camp-sidebar').addEventListener('click', function (e) {
    var link = e.target.closest('a');
    if (!link) return;
    var href = link.getAttribute('href') || '';
    if (!href.startsWith('#')) return;
    e.preventDefault();
    var targetId = href.substring(1);
    showSection(targetId);
    history.replaceState(null, '', '#' + targetId);
});

var hash = location.hash.substring(1);
if (hash && document.getElementById(hash)) {
    showSection(hash);
} else {
    var first = document.querySelector('.camp-section');
    if (first) showSection(first.id);
}
