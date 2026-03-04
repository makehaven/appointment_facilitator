(function () {
  function removeNodeAddEntry(path) {
    document.querySelectorAll('a[href="' + path + '"]').forEach(function (link) {
      // Node add list usually renders as <dt>/<dd>; remove both.
      var dt = link.closest('dt');
      if (dt) {
        var dd = dt.nextElementSibling;
        dt.remove();
        if (dd && dd.tagName === 'DD') {
          dd.remove();
        }
        return;
      }

      // Sidebar create menus usually render as list items.
      var li = link.closest('li');
      if (li) {
        li.remove();
        return;
      }

      // Fallback for card/list wrappers.
      var wrapper = link.closest('div');
      if (wrapper) {
        wrapper.remove();
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    removeNodeAddEntry('/node/add/appointment');
    removeNodeAddEntry('/node/add/reservation');
  });
}());
