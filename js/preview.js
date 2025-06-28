(function (Drupal, drupalSettings) {
  Drupal.behaviors.fileAdoptionPreview = {
    attach: function (context, settings) {
      if (context !== document) {
        return;
      }
      const dirsUrl = drupalSettings.file_adoption.dirs_url;
      const examplesUrl = drupalSettings.file_adoption.examples_url;
      const countsUrl = drupalSettings.file_adoption.counts_url;
      const patterns = drupalSettings.file_adoption.ignore_patterns || [];

      const regexes = patterns.map(function (pattern) {
        const esc = pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&');
        const re = '^' + esc.replace(/\*/g, '.*').replace(/\?/g, '.') + '$';
        return new RegExp(re);
      });

      function matchesPattern(path) {
        return regexes.some(function (re) { return re.test(path); });
      }
      const wrapper = document.getElementById('file-adoption-preview');
      const details = document.getElementById('file-adoption-preview-wrapper');
      const results = document.getElementById('file-adoption-results');
      if (!dirsUrl || !wrapper) {
        return;
      }

      const maxFailures = 3;
      let failureCount = 0;

      if (results) {
        results.style.display = 'none';
      }

      function showResults() {
        if (results) {
          results.style.display = '';
        }
      }

      function handleFailure(error) {
        failureCount += 1;
        if (console && error) {
          console.error('File Adoption preview error:', error);
        }
        if (failureCount >= maxFailures) {
          wrapper.textContent = Drupal.t('Unable to load preview. Please try again later.');
          clearInterval(intervalId);
          showResults();
        }
      }

      const data = { dirs: [], examples: {}, counts: {} };
      let step = 'dirs';

      function render() {
        const dirs = data.dirs;
        const examples = data.examples;
        const counts = data.counts;
        let html = '<ul>';
        if (dirs.length) {
          const rootExample = examples[''] ? ' (e.g., ' + Drupal.checkPlain(examples['']) + ')' : '';
          const rootCount = typeof counts[''] !== 'undefined' ? ' (' + counts[''] + ')' : '';
          const rootLabel = Drupal.checkPlain('public://') + rootExample + rootCount;
          if (matchesPattern('') || matchesPattern('/*')) {
            html += '<li><span style="color:gray">' + rootLabel + '</span></li>';
          }
          else {
            html += '<li>' + rootLabel + '</li>';
          }
          dirs.forEach(function (dir) {
            let label = dir + '/';
            if (examples[dir]) {
              label += ' (e.g., ' + Drupal.checkPlain(examples[dir]) + ')';
            }
            if (typeof counts[dir] !== 'undefined' && counts[dir] > 0) {
              label += ' (' + counts[dir] + ')';
            }
            const dirPath = dir;
            const ignored = matchesPattern(dirPath) || matchesPattern(dirPath + '/*');
            if (ignored) {
              html += '<li><span style="color:gray">' + Drupal.checkPlain(label) + '</span></li>';
            }
            else {
              html += '<li>' + Drupal.checkPlain(label) + '</li>';
            }
          });
        }
        html += '</ul>';
        wrapper.innerHTML = '<div>' + html + '</div>';
        if (details && typeof counts[''] !== 'undefined') {
          const summary = details.querySelector('summary');
          if (summary) {
            summary.textContent = drupalSettings.file_adoption.preview_title + ' (' + counts[''] + ')';
          }
        }
      }

      function loadStep() {
        const url = step === 'dirs' ? dirsUrl : (step === 'examples' ? examplesUrl : countsUrl);
        fetch(url)
          .then((response) => response.json())
          .then((resp) => {
            if (step === 'dirs' && Array.isArray(resp.dirs)) {
              data.dirs = resp.dirs;
              step = 'examples';
              failureCount = 0;
              render();
            }
            else if (step === 'examples' && resp.examples) {
              data.examples = resp.examples;
              step = 'counts';
              failureCount = 0;
              render();
            }
            else if (step === 'counts' && resp.counts) {
              data.counts = resp.counts;
              failureCount = 0;
              render();
              clearInterval(intervalId);
              showResults();
            }
            else {
              handleFailure('Invalid response');
            }
          })
          .catch((err) => handleFailure(err));
      }

      const intervalId = setInterval(loadStep, 3000);
      loadStep();
    }
  };
})(Drupal, drupalSettings);
