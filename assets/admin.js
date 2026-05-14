(() => {
  const wrap = document.querySelector('.mhbi-wrap');
  const startBtn = document.getElementById('mhbi-start-btn');
  const stopButtons = Array.from(document.querySelectorAll('[data-mhbi-stop]'));
  const stopBtn = document.getElementById('mhbi-stop-btn');
  const resetBtn = document.getElementById('mhbi-reset-btn');
  const fullResetBtn = document.getElementById('mhbi-full-reset-btn');
  const clearLogBtn = document.getElementById('mhbi-clear-log-btn');
  const statusEl = document.getElementById('mhbi-status');
  const logEl = document.getElementById('mhbi-log');
  const noticeEl = document.getElementById('mhbi-notice');
  const statusBadgeEl = document.getElementById('mhbi-status-badge');
  const phaseLabelEl = document.getElementById('mhbi-phase-label');
  const batchLabelEl = document.getElementById('mhbi-batch-label');
  const progressFillEl = document.getElementById('mhbi-progress-fill');
  const progressTextEl = document.getElementById('mhbi-progress-text');
  const tabButtons = Array.from(document.querySelectorAll('.mhbi-tab'));
  const tabPanels = Array.from(document.querySelectorAll('.mhbi-panel'));
  const tabJumps = Array.from(document.querySelectorAll('.mhbi-tab-jump'));

  if (!wrap || !startBtn || !stopButtons.length || !resetBtn || !fullResetBtn || !statusEl || !logEl || typeof mhbiAdmin === 'undefined') {
    return;
  }

  let running = wrap.dataset.initialRunning === '1';
  let knownTotalPosts = Number.parseInt(wrap.dataset.initialTotalPosts || '0', 10) || 0;
  let currentPhase = wrap.dataset.initialPhase || 'posts';

  const fields = {
    blogId: document.getElementById('mhbi_blog_id'),
    downloadImages: document.getElementById('mhbi_download_images'),
    importPages: document.getElementById('mhbi_import_pages'),
    enableRedirects: document.getElementById('mhbi_enable_redirects'),
    redirect404Home: document.getElementById('mhbi_redirect_404_home'),
    batchSize: document.getElementById('mhbi_batch_size'),
    processed: document.getElementById('mhbi-processed'),
    imported: document.getElementById('mhbi-imported'),
    updated: document.getElementById('mhbi-updated'),
    skipped: document.getElementById('mhbi-skipped'),
    errors: document.getElementById('mhbi-errors'),
  };

  const activateTab = (tabName, pushHash = false) => {
    if (!tabName) return;

    tabButtons.forEach((button) => {
      const isActive = button.dataset.tab === tabName;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-selected', isActive ? 'true' : 'false');
      button.tabIndex = isActive ? 0 : -1;
    });

    tabPanels.forEach((panel) => {
      const isActive = panel.dataset.panel === tabName;
      panel.classList.toggle('is-active', isActive);
      panel.hidden = !isActive;
    });

    if (pushHash) {
      const hash = `#tab-${tabName}`;
      if (window.location.hash !== hash) {
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', hash);
        } else {
          window.location.hash = hash;
        }
      }
    }
  };

  const initialHashMatch = (window.location.hash || '').match(/^#tab-([a-z0-9_-]+)$/i);
  if (initialHashMatch && tabButtons.some((button) => button.dataset.tab === initialHashMatch[1])) {
    activateTab(initialHashMatch[1]);
  } else {
    activateTab('dashboard');
  }

  tabButtons.forEach((button) => {
    button.addEventListener('click', () => activateTab(button.dataset.tab, true));
  });

  tabJumps.forEach((button) => {
    button.addEventListener('click', () => activateTab(button.dataset.targetTab, true));
  });

  const setButtonLoading = (button, state) => {
    if (!button) return;
    button.classList.toggle('is-loading', !!state);
    button.setAttribute('aria-busy', state ? 'true' : 'false');
  };

  const formatI18n = (template, replacements = []) => {
    if (!template) return '';

    let message = template;

    replacements.forEach((replacement, index) => {
      const numberedPlaceholder = `%${index + 1}$s`;
      const numberedNumericPlaceholder = `%${index + 1}$d`;

      if (message.includes(numberedPlaceholder)) {
        message = message.replace(numberedPlaceholder, String(replacement));
        return;
      }

      if (message.includes(numberedNumericPlaceholder)) {
        message = message.replace(numberedNumericPlaceholder, String(replacement));
        return;
      }

      if (message.includes('%s')) {
        message = message.replace('%s', String(replacement));
        return;
      }

      if (message.includes('%d')) {
        message = message.replace('%d', String(replacement));
      }
    });

    return message;
  };

  const getErrorLogMessage = (message) => formatI18n(mhbiAdmin.i18n.errorLog, [message || mhbiAdmin.i18n.error]);

  const showNotice = (message, type = 'success') => {
    if (!noticeEl) return;
    if (!message) {
      noticeEl.hidden = true;
      noticeEl.textContent = '';
      noticeEl.className = 'mhbi-notice';
      return;
    }

    noticeEl.hidden = false;
    noticeEl.textContent = message;
    noticeEl.className = `mhbi-notice is-${type}`;
  };

  const setStatus = (text) => {
    statusEl.textContent = text || '';
  };

  const setBadgeState = (state) => {
    if (!statusBadgeEl) return;
    statusBadgeEl.classList.remove('is-ready', 'is-running', 'is-complete', 'is-stopped');

    if (state === 'running') {
      statusBadgeEl.classList.add('is-running');
      statusBadgeEl.textContent = mhbiAdmin.i18n.running;
    } else if (state === 'complete') {
      statusBadgeEl.classList.add('is-complete');
      statusBadgeEl.textContent = mhbiAdmin.i18n.finished;
    } else if (state === 'stopped') {
      statusBadgeEl.classList.add('is-stopped');
      statusBadgeEl.textContent = mhbiAdmin.i18n.stoppedBadge;
    } else {
      statusBadgeEl.classList.add('is-ready');
      statusBadgeEl.textContent = mhbiAdmin.i18n.ready;
    }
  };

  const setPhaseLabel = (phase, job = {}) => {
    currentPhase = phase || job.phase || 'posts';
    if (!phaseLabelEl) return;

    if (job.complete) {
      phaseLabelEl.textContent = mhbiAdmin.i18n.finished;
      return;
    }

    if (currentPhase === 'pages') {
      phaseLabelEl.textContent = mhbiAdmin.i18n.pagesPhase;
    } else if (currentPhase === 'posts') {
      phaseLabelEl.textContent = mhbiAdmin.i18n.postsPhase;
    } else {
      phaseLabelEl.textContent = mhbiAdmin.i18n.unknownPhase;
    }
  };

  const calculateProgress = (job = {}) => {
    const processed = Number.parseInt(job.processed ?? 0, 10) || 0;
    const totalPosts = Number.parseInt(job.total_posts ?? knownTotalPosts ?? 0, 10) || 0;
    const phase = job.phase || currentPhase || 'posts';

    if (job.complete) return 100;
    if (job.stopped && processed > 0) return Math.min(99, Math.max(8, progressFillEl ? parseFloat(progressFillEl.style.width || '0') : 0));
    if (phase === 'pages') return 95;
    if (totalPosts > 0) return Math.min(94, Math.max(4, Math.round((processed / Math.max(1, totalPosts)) * 94)));
    if (running) return 12;
    return 0;
  };

  const updateProgressUi = (job = {}) => {
    const processed = Number.parseInt(job.processed ?? fields.processed?.textContent ?? '0', 10) || 0;
    const totalPosts = Number.parseInt(job.total_posts ?? knownTotalPosts ?? 0, 10) || 0;
    knownTotalPosts = totalPosts || knownTotalPosts;
    const percent = calculateProgress(job);

    if (progressFillEl) {
      progressFillEl.style.width = `${percent}%`;
    }

    if (!progressTextEl) return;

    if (job.complete) {
      const progressCompleteTemplate = processed === 1
        ? mhbiAdmin.i18n.progressCompleteSingle
        : mhbiAdmin.i18n.progressCompletePlural;

      progressTextEl.textContent = formatI18n(progressCompleteTemplate, [processed]);
      return;
    }

    if ((job.phase || currentPhase) === 'pages') {
      progressTextEl.textContent = mhbiAdmin.i18n.pagesFinalizing;
      return;
    }

    if (totalPosts > 0) {
      progressTextEl.textContent = formatI18n(mhbiAdmin.i18n.progressCount, [processed, totalPosts]);
    } else if (running) {
      progressTextEl.textContent = mhbiAdmin.i18n.processing;
    } else {
      progressTextEl.textContent = mhbiAdmin.i18n.idle;
    }
  };

  const appendLogs = (logs = []) => {
    if (!Array.isArray(logs) || !logs.length) return;
    const current = logEl.textContent ? `${logEl.textContent}\n` : '';
    logEl.textContent = current + logs.join('\n');
    logEl.scrollTop = logEl.scrollHeight;
  };

  const updateStats = (job = {}) => {
    fields.processed.textContent = job.processed ?? 0;
    fields.imported.textContent = job.imported ?? 0;
    fields.updated.textContent = job.updated ?? 0;
    fields.skipped.textContent = job.skipped ?? 0;
    fields.errors.textContent = job.errors ?? 0;
    if (job.total_posts !== undefined) {
      knownTotalPosts = Number.parseInt(job.total_posts, 10) || knownTotalPosts;
    }
    if (job.phase) {
      setPhaseLabel(job.phase, job);
    }
    updateProgressUi(job);
  };

  const resetStats = () => {
    knownTotalPosts = 0;
    updateStats({ processed: 0, imported: 0, updated: 0, skipped: 0, errors: 0, total_posts: 0, phase: 'posts' });
  };

  const setRunningState = (isRunning) => {
    running = !!isRunning;
    startBtn.disabled = running;
    stopButtons.forEach((button) => {
      button.disabled = !running;
    });

    if (fields.blogId) fields.blogId.readOnly = running;
    if (fields.batchSize) fields.batchSize.readOnly = running;
    ['downloadImages', 'importPages', 'enableRedirects', 'redirect404Home'].forEach((key) => {
      if (fields[key]) fields[key].disabled = running;
    });

    setBadgeState(running ? 'running' : 'ready');
    updateProgressUi({ phase: currentPhase, total_posts: knownTotalPosts, processed: Number.parseInt(fields.processed?.textContent || '0', 10) || 0 });
  };

  const request = async (action, payload = {}) => {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', mhbiAdmin.nonce);
    Object.entries(payload).forEach(([key, value]) => {
      formData.append(key, value);
    });

    const response = await fetch(mhbiAdmin.ajaxUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
    });

    const data = await response.json();
    if (!data.success) {
      throw new Error(data?.data?.message || mhbiAdmin.i18n.error);
    }

    return data.data;
  };

  const processLoop = async () => {
    while (running) {
      setStatus(mhbiAdmin.i18n.processing);
      const data = await request('mhbi_process_batch');
      updateStats(data.job || {});
      appendLogs(data.logs || []);
      setStatus(data.message || mhbiAdmin.i18n.processing);

      if (data.job?.stopped) {
        setRunningState(false);
        setBadgeState('stopped');
        setStatus(data.message || mhbiAdmin.i18n.stopped);
        appendLogs([mhbiAdmin.i18n.pausedNotice]);
        showNotice(data.message || mhbiAdmin.i18n.stopped, 'warning');
        break;
      }

      if (data.job?.complete) {
        setRunningState(false);
        setBadgeState('complete');
        setPhaseLabel('complete', { complete: true });
        setStatus(mhbiAdmin.i18n.done);
        updateProgressUi({ complete: true, processed: data.job?.processed ?? 0, total_posts: data.job?.total_posts ?? knownTotalPosts });
        showNotice(mhbiAdmin.i18n.done, 'success');
        break;
      }

      await new Promise((resolve) => setTimeout(resolve, 350));
    }
  };

  startBtn.addEventListener('click', async () => {
    if (running) return;

    showNotice('');
    logEl.textContent = '';
    setButtonLoading(startBtn, true);
    setRunningState(true);
    activateTab('dashboard', true);

    try {
      setStatus(mhbiAdmin.i18n.starting);
      batchLabelEl && (batchLabelEl.textContent = fields.batchSize.value || '10');
      const data = await request('mhbi_start_import', {
        blog_id: fields.blogId.value.trim(),
        download_images: fields.downloadImages.checked ? '1' : '0',
        import_pages: fields.importPages.checked ? '1' : '0',
        enable_redirects: fields.enableRedirects.checked ? '1' : '0',
        redirect_404_home: fields.redirect404Home.checked ? '1' : '0',
        batch_size: fields.batchSize.value || '10',
      });
      currentPhase = data.job?.phase || 'posts';
      knownTotalPosts = Number.parseInt(data.job?.total_posts ?? 0, 10) || 0;
      updateStats(data.job || {});
      setPhaseLabel(currentPhase, data.job || {});
      setStatus(data.message || mhbiAdmin.i18n.starting);
      showNotice(data.message || mhbiAdmin.i18n.starting, 'success');
      await processLoop();
    } catch (error) {
      setRunningState(false);
      setBadgeState('ready');
      setStatus(error.message || mhbiAdmin.i18n.error);
      appendLogs([getErrorLogMessage(error.message)]);
      showNotice(error.message || mhbiAdmin.i18n.error, 'error');
    } finally {
      setButtonLoading(startBtn, false);
    }
  });

  const handleStopImport = async (button) => {
    if (!running) return;
    if (!window.confirm(mhbiAdmin.i18n.confirmStop)) return;

    stopButtons.forEach((item) => setButtonLoading(item, item === button));
    try {
      const data = await request('mhbi_stop_import');
      setRunningState(false);
      setBadgeState('stopped');
      setStatus(data.message || mhbiAdmin.i18n.stopped);
      appendLogs([data.message || mhbiAdmin.i18n.stopped]);
      showNotice(data.message || mhbiAdmin.i18n.stopped, 'warning');
      activateTab('dashboard', true);
    } catch (error) {
      setRunningState(false);
      setBadgeState('stopped');
      setStatus(error.message || mhbiAdmin.i18n.error);
      appendLogs([getErrorLogMessage(error.message)]);
      showNotice(error.message || mhbiAdmin.i18n.error, 'error');
    } finally {
      stopButtons.forEach((item) => setButtonLoading(item, false));
    }
  };

  stopButtons.forEach((button) => {
    button.addEventListener('click', async () => {
      await handleStopImport(button);
    });
  });

  resetBtn.addEventListener('click', async () => {
    if (!window.confirm(mhbiAdmin.i18n.confirmReset)) return;

    setButtonLoading(resetBtn, true);
    try {
      setRunningState(false);
      const data = await request('mhbi_reset_import');
      setBadgeState('ready');
      setPhaseLabel('posts', { phase: 'posts' });
      setStatus(data.message || mhbiAdmin.i18n.reset);
      resetStats();
      logEl.textContent = '';
      showNotice(data.message || mhbiAdmin.i18n.reset, 'success');
      activateTab('tools', true);
    } catch (error) {
      setStatus(error.message || mhbiAdmin.i18n.error);
      showNotice(error.message || mhbiAdmin.i18n.error, 'error');
    } finally {
      setButtonLoading(resetBtn, false);
    }
  });

  fullResetBtn.addEventListener('click', async () => {
    if (!window.confirm(mhbiAdmin.i18n.confirmFullReset)) return;

    setButtonLoading(fullResetBtn, true);
    try {
      setRunningState(false);
      const data = await request('mhbi_full_reset');
      setBadgeState('ready');
      setPhaseLabel('posts', { phase: 'posts' });
      setStatus(data.message || mhbiAdmin.i18n.fullReset);
      resetStats();
      logEl.textContent = '';
      if (fields.blogId) fields.blogId.value = '';
      if (fields.downloadImages) fields.downloadImages.checked = true;
      if (fields.importPages) fields.importPages.checked = true;
      if (fields.enableRedirects) fields.enableRedirects.checked = true;
      if (fields.redirect404Home) fields.redirect404Home.checked = true;
      if (fields.batchSize) fields.batchSize.value = '10';
      if (batchLabelEl) batchLabelEl.textContent = '10';
      showNotice(data.message || mhbiAdmin.i18n.fullReset, 'success');
      activateTab('tools', true);
    } catch (error) {
      setStatus(error.message || mhbiAdmin.i18n.error);
      appendLogs([getErrorLogMessage(error.message)]);
      showNotice(error.message || mhbiAdmin.i18n.error, 'error');
    } finally {
      setButtonLoading(fullResetBtn, false);
    }
  });

  clearLogBtn?.addEventListener('click', () => {
    logEl.textContent = '';
    showNotice(mhbiAdmin.i18n.clearLog, 'success');
  });

  if (fields.batchSize && batchLabelEl) {
    fields.batchSize.addEventListener('input', () => {
      batchLabelEl.textContent = fields.batchSize.value || '10';
    });
  }

  if (running) {
    setBadgeState('running');
    setPhaseLabel(currentPhase, { phase: currentPhase });
    updateProgressUi({
      phase: currentPhase,
      total_posts: knownTotalPosts,
      processed: Number.parseInt(fields.processed?.textContent || '0', 10) || 0,
    });
    showNotice(mhbiAdmin.i18n.resumeHint, 'warning');
  } else if (wrap.dataset.initialComplete === '1') {
    setBadgeState('complete');
    setPhaseLabel('complete', { complete: true });
    updateProgressUi({ complete: true, processed: Number.parseInt(fields.processed?.textContent || '0', 10) || 0, total_posts: knownTotalPosts });
  } else if (wrap.dataset.initialStopped === '1') {
    setBadgeState('stopped');
    setPhaseLabel(currentPhase, { phase: currentPhase });
    updateProgressUi({ phase: currentPhase, processed: Number.parseInt(fields.processed?.textContent || '0', 10) || 0, total_posts: knownTotalPosts, stopped: true });
    showNotice(mhbiAdmin.i18n.pausedNotice, 'warning');
  } else {
    setBadgeState('ready');
    setPhaseLabel(currentPhase, { phase: currentPhase });
    updateProgressUi({ phase: currentPhase, total_posts: knownTotalPosts, processed: Number.parseInt(fields.processed?.textContent || '0', 10) || 0 });
  }

  setRunningState(running);
})();
