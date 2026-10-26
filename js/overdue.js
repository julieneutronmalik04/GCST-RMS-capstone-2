'use strict';

(() => {
  const body = document.getElementById('overdueTableBody');
  if (!body) return;
  const endpoint = new URL('../modules/overdue.php', document.currentScript.src);
  const count = document.getElementById('overdueCount');
  const feedback = document.getElementById('overdueFeedback');
  const refresh = document.getElementById('refreshOverdueBtn');
  const previewDialog = document.getElementById('overdueSmsPreviewDialog');
  const previewDetails = document.getElementById('overdueSmsPreviewDetails');
  const previewMessage = document.getElementById('overdueSmsPreviewMessage');
  let previewVersion = 0;
  let smsMode = 'unconfigured';
  let loading = false;
  const smsStatusLabel = status => ({ Accepted: 'Accepted by provider', Simulated: 'Preview recorded', Failed: 'Not sent', Skipped: 'Not sent', Unknown: 'Confirmation pending' }[status] || status || 'Not notified');

  function text(parent, tag, value, className) {
    const element = document.createElement(tag);
    element.textContent = value;
    if (className) element.className = className;
    parent.append(element);
    return element;
  }
  function message(value, error = false) {
    feedback.textContent = value;
    feedback.hidden = !value;
    feedback.classList.toggle('overdue-error', error);
  }
  async function request(options, params = {}) {
    const url = new URL(endpoint);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load overdue records.');
    return data;
  }
  async function showPreview(id) {
    const version = ++previewVersion;
    previewDetails.textContent = 'Loading recorded SMS...';
    previewMessage.textContent = '';
    previewDialog.showModal();
    try {
      // This read-only endpoint runs before overdue checking and never calls the gateway.
      const data = await request(undefined, { action: 'sms_preview', id });
      if (version !== previewVersion || !previewDialog.open) return;
      const sms = data.sms;
      previewDetails.textContent = `To: ${sms.contact_number || 'Not recorded'} | ${sms.equip_code} | ${smsStatusLabel(sms.status)} | ${sms.processed_at || sms.notification_date}`;
      previewMessage.textContent = sms.message;
    } catch (error) {
      if (version === previewVersion && previewDialog.open) previewDetails.textContent = error.message;
    }
  }
  previewDialog.addEventListener('close', () => { previewVersion++; });
  document.querySelectorAll('[data-close-sms-preview]').forEach(button => button.addEventListener('click', () => previewDialog.close()));
  function render(rows) {
    body.replaceChildren();
    count.textContent = String(rows.length);
    if (!rows.length) {
      const cell = text(text(body, 'tr', ''), 'td', 'No overdue equipment.');
      cell.colSpan = 6;
      return;
    }
    for (const loan of rows) {
      const row = text(body, 'tr', '');
      const borrower = text(row, 'td', '');
      text(borrower, 'strong', loan.borrower_name);
      text(borrower, 'small', loan.student_faculty_id || 'No registered borrower linked');
      text(borrower, 'small', loan.contact_number || 'No contact number');
      if (loan.email) text(borrower, 'small', loan.email);
      const equipment = text(row, 'td', '');
      text(equipment, 'strong', loan.equip_code);
      text(equipment, 'small', loan.equipment_name);
      text(row, 'td', `${loan.due_date} ${loan.expected_return_time || ''}`.trim());
      text(row, 'td', String(loan.days_overdue));
      text(row, 'td', Number(loan.penalty_amount).toFixed(2));
      const actions = text(row, 'td', '');
      const status = smsStatusLabel(loan.sms_status);
      text(actions, 'small', status);
      if (loan.sms_error) text(actions, 'small', 'Delivery requires attention.', 'overdue-error');
      const preview = text(actions, 'button', 'Preview SMS', 'btn btn-secondary btn-sm');
      preview.type = 'button';
      preview.setAttribute('aria-label', `Preview recorded SMS for ${loan.equip_code}`);
      preview.addEventListener('click', () => showPreview(loan.id));
      if (!loan.student_faculty_id) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'modal-form-input overdue-link-input';
        input.placeholder = 'Student/Faculty ID';
        input.setAttribute('aria-label', `Registered borrower ID for ${loan.equip_code}`);
        actions.append(input);
        const link = text(actions, 'button', 'Link borrower', 'btn btn-secondary btn-sm');
        link.type = 'button';
        link.addEventListener('click', () => act(link, { action: 'link_borrower', id: loan.id, student_faculty_id: input.value.trim() }));
      } else if (smsMode === 'live') {
        const notify = text(actions, 'button', 'Send SMS', 'btn btn-primary btn-sm');
        notify.type = 'button';
        const today = loan.sms_processed_at?.slice(0, 10);
        const handledToday = loan.sms_notification_date === loan.database_today;
        // The server enforces daily deduplication; the UI also prevents repeated accepted sends.
        notify.disabled = !loan.contact_number || (handledToday && ['Accepted', 'Processing', 'Unknown'].includes(loan.sms_status));
        if (handledToday && loan.sms_status === 'Accepted') notify.textContent = today ? `Accepted ${today}` : 'Accepted';
        notify.addEventListener('click', () => act(notify, { action: 'notify', id: loan.id }));
      }
    }
  }
  async function load() {
    if (loading) return;
    loading = true;
    refresh.disabled = true;
    try {
      const data = await request();
      smsMode = data.sms_mode;
      render(data.overdue);
    } catch (error) {
      message('Unable to load overdue records. Select Check overdue to retry.', true);
    } finally {
      loading = false;
      refresh.disabled = false;
    }
  }
  async function act(button, fields) {
    button.disabled = true;
    message('');
    try {
      const data = await request({ method: 'POST', body: new URLSearchParams(fields) });
      message(data.message);
    } catch (error) {
      message(error.message, true);
    } finally {
      button.disabled = false;
      await load();
    }
  }
  refresh.addEventListener('click', () => { message(''); load(); });
  window.addEventListener('borrowing:changed', load);
  setInterval(() => {
    if (!document.hidden && document.getElementById('tab-borrowing')?.classList.contains('active')) load();
  }, 60000);
  load();
})();
