// common.js - small helpers used on most pages

// stop database text from being treated as html
function esc(value) {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// show "-" when a value is missing
function show(value) {
  if (value === null || value === undefined || value === '') return '-';
  return esc(value);
}

// the api sometimes returns a plain array and sometimes {something: [...]}
function listFrom(data, key) {
  if (Array.isArray(data)) return data;
  if (data && Array.isArray(data[key])) return data[key];
  return [];
}

// put a message in a <div class="message"> (type = ok / error / '')
function showMessage(id, text, type) {
  var box = document.getElementById(id);
  if (!box) return;
  if (!text) {
    box.style.display = 'none';
    return;
  }
  box.className = 'message ' + (type || '');
  box.textContent = text;
  box.style.display = 'block';
}

// fill the department dropdown from the database
function fillDepartments(selectId) {
  var select = document.getElementById(selectId);
  if (!select) return Promise.resolve();
  var firstLabel = select.options[0].textContent;

  return API.getDepartments().then(function (data) {
    var html = '<option value="All">' + esc(firstLabel) + '</option>';
    listFrom(data, 'departments').forEach(function (d) {
      html += '<option value="' + esc(d.name) + '">' + esc(d.name) + '</option>';
    });
    select.innerHTML = html;
  }).catch(function (err) {
    console.log('could not load departments', err);
  });
}

// fill the semester dropdown (value = semester number)
function fillSemesters(selectId) {
  var select = document.getElementById(selectId);
  if (!select) return Promise.resolve();
  var firstLabel = select.options[0].textContent;

  return API.getSemesters().then(function (data) {
    var html = '<option value="All">' + esc(firstLabel) + '</option>';
    listFrom(data, 'semesters').forEach(function (s) {
      html += '<option value="' + s.semesterNumber + '">Semester ' + s.semesterNumber + '</option>';
    });
    select.innerHTML = html;
  }).catch(function (err) {
    console.log('could not load semesters', err);
  });
}
