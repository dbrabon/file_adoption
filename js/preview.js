(function (Drupal, drupalSettings) {
  Drupal.behaviors.fileAdoptionPreview = {
    attach: function (context, settings) {
      if (context !== document) {
        return;
      }
      const url = drupalSettings.file_adoption.preview_url;
      const wrapper = document.getElementById('file-adoption-preview');
      const details = document.getElementById('file-adoption-preview-wrapper');
      if (!url || !wrapper) {
        return;
      }

      function loadPreview() {
        fetch(url)
          .then((response) => response.json())
          .then((data) => {
            if (data.markup) {
              wrapper.innerHTML = data.markup;
              if (details && typeof data.count !== 'undefined') {
                const summary = details.querySelector('summary');
                if (summary) {
                  summary.textContent = drupalSettings.file_adoption.preview_title + ' (' + data.count + ')';
                }
              }
              clearInterval(intervalId);
            }
          })
          .catch(() => {});
      }

      const intervalId = setInterval(loadPreview, 3000);
      loadPreview();
    }
  };
})(Drupal, drupalSettings);
