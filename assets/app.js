// app.js
// Global JS for DOMLearn: theme toggle and lesson test handlers.

(function(){
  var KEY = 'domlearn-theme';

  document.addEventListener('DOMContentLoaded', function(){
    // Apply saved theme once DOM is ready
    try {
      var saved = localStorage.getItem(KEY);
      if (saved) {
        document.body.classList.remove('theme-dark','theme-light');
        document.body.classList.add(saved);
      }
    } catch (e) {
    }
    // Theme toggle
    var btn = document.getElementById('themeToggle');
    if (btn) {
      btn.addEventListener('click', function(){
        var cur = document.body.classList.contains('theme-dark') ? 'theme-dark' : 'theme-light';
        var next = cur === 'theme-dark' ? 'theme-light' : 'theme-dark';
        document.body.classList.remove('theme-dark','theme-light');
        document.body.classList.add(next);
        try {
          localStorage.setItem(KEY, next);
        } catch (e) {
        }
      });
    }

    // Lesson tests: instant check
    document.querySelectorAll('.test-question').forEach(function(qEl){
      var correct = parseInt(qEl.dataset.correct||'-1',10);
      var answered = false;
      qEl.querySelectorAll('.answer').forEach(function(btn, i){
        btn.addEventListener('click', function(){
          if (answered) return;
          answered = true;
          var idx = parseInt(btn.dataset.idx,10);
          qEl.querySelectorAll('.answer').forEach(function(b, j){
            if (j === correct) {
              b.classList.add('correct');
              b.textContent = '✔ ' + b.textContent;
            }
            if (j === idx && j !== correct) {
              b.classList.add('wrong');
              b.textContent = '✘ ' + b.textContent;
            }
            b.disabled = true;
          });
        });
      });
    });

    // Hide topbar when page is scrolled, show only at the very top
    var topbar = document.querySelector('.topbar');
    if (topbar) {
      var applyTopbarState = function(){
        if (window.scrollY > 0) {
          topbar.classList.add('topbar--hidden');
        } else {
          topbar.classList.remove('topbar--hidden');
        }
      };
      applyTopbarState();
      window.addEventListener('scroll', applyTopbarState, { passive: true });
    }
  });

  // Add copy buttons to code blocks
  document.querySelectorAll('.lesson pre').forEach(pre => {
    // Create copy button
    const button = document.createElement('button');
    button.className = 'code-copy-btn';
    button.title = 'Копировать код';
    button.innerHTML = `
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
      </svg>
      <span>Копировать</span>
    `;
    
    // Position the button
    pre.style.position = 'relative';
    pre.appendChild(button);

    // Add click event
    button.addEventListener('click', async () => {
      const code = pre.querySelector('code')?.textContent || pre.textContent;
      
      try {
        await navigator.clipboard.writeText(code);
        
        // Show success feedback
        const originalText = button.innerHTML;
        button.innerHTML = `
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <span>Скопировано!</span>
        `;
        button.classList.add('copied');
        
        // Reset button after 2 seconds
        setTimeout(() => {
          button.innerHTML = originalText;
          button.classList.remove('copied');
        }, 2000);
      } catch (err) {
        console.error('Failed to copy code: ', err);
        button.textContent = 'Ошибка копирования';
        setTimeout(() => {
          button.innerHTML = originalText;
        }, 2000);
      }
    });
  });
})();;
