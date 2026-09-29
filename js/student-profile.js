// student-profile.js - one student's full profile, loaded from api/student.php

var params = new URLSearchParams(window.location.search);
var studentId = (params.get('id') || '').trim();
var student = null;
var editSection = '';

var TABS = ['overview', 'academic', 'attendance', 'results', 'documents'];

// fields that can be edited from the overview tab
var editConfig = {
  personal: {
    title: 'Edit Personal Information',
    fields: [
      { key: 'course', label: 'Course', type: 'text' },
      { key: 'section', label: 'Section', type: 'text', check: /^[A-Za-z]{0,3}$/, error: 'Section must be 1-3 letters.' },
      { key: 'dob', label: 'Date of Birth', type: 'date' },
      { key: 'gender', label: 'Gender', type: 'select', options: ['', 'Male', 'Female', 'Other'] },
      { key: 'email', label: 'Email', type: 'email', check: /^([^\s@]+@[^\s@]+\.[^\s@]+)?$/, error: 'Enter a valid email address.' },
      { key: 'phone', label: 'Phone', type: 'tel', check: /^(\+?[0-9\s\-]{8,20})?$/, error: 'Enter a valid phone number.' }
    ]
  },
  contact: {
    title: 'Edit Contact / Emergency',
    fields: [
      { key: 'guardianName', label: 'Parent / Guardian Name', type: 'text' },
      { key: 'emergencyContact', label: 'Emergency Contact', type: 'tel', check: /^(\+?[0-9\s\-]{8,20})?$/, error: 'Enter a valid phone number.' },
      { key: 'guardianPhone', label: 'Parent Phone', type: 'tel', check: /^(\+?[0-9\s\-]{8,20})?$/, error: 'Enter a valid phone number.' },
      { key: 'relationship', label: 'Relationship', type: 'text' }
    ]
  }
};

// ---------------------------------------------------------------
// small helpers
// ---------------------------------------------------------------
function el(id) {
  return document.getElementById(id);
}

function roman(n) {
  var table = [[10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']];
  var out = '';
  table.forEach(function (pair) {
    while (n >= pair[0]) {
      out += pair[1];
      n -= pair[0];
    }
  });
  return out;
}

function num(value, decimals) {
  var n = Number(value);
  if (value === null || value === undefined || isNaN(n)) return '-';
  return n.toFixed(decimals || 0);
}

function formatDate(iso) {
  if (!iso) return '-';
  var p = String(iso).split('-');
  var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  if (p.length !== 3 || !months[parseInt(p[1], 10) - 1]) return esc(iso);
  return parseInt(p[2], 10) + ' ' + months[parseInt(p[1], 10) - 1] + ' ' + p[0];
}

// builds a two column "label | value" table
function detailsTable(rows) {
  var html = '<table class="details">';
  rows.forEach(function (row) {
    html += '<tr><th>' + esc(row[0]) + '</th><td>' + show(row[1]) + '</td></tr>';
  });
  return html + '</table>';
}

// ---------------------------------------------------------------
// load
// ---------------------------------------------------------------
function loadProfile() {
  if (!studentId) {
    showMessage('profileMessage', 'No student selected. Open a student from the Students page.', 'error');
    return;
  }

  API.getStudent(studentId).then(function (data) {
    student = data;
    showMessage('profileMessage', '');
    el('profile').style.display = 'block';
    showProfile();
  }).catch(function (err) {
    showMessage('profileMessage', 'Could not load this student: ' + err.message, 'error');
  });
}

function showProfile() {
  var s = student;

  document.title = s.name + ' - NEX College of Arts and Science';
  el('photo').src = s.photo || s.avatar || 'assets/images/logo.png';
  el('pName').textContent = s.name;
  el('pRoll').textContent = 'Roll No: ' + s.rollNo;
  el('pCourse').textContent = s.course || s.dept;
  el('pYearSem').textContent = 'Year ' + roman(s.year) + ', Semester ' + roman(s.semester);

  el('tab-overview').innerHTML = overviewHtml();
  el('tab-academic').innerHTML = academicHtml();
  el('tab-attendance').innerHTML = attendanceHtml();
  el('tab-results').innerHTML = resultsHtml();
  el('tab-documents').innerHTML = documentsHtml();

  showResultsForSemester();
}

// ---------------------------------------------------------------
// tabs
// ---------------------------------------------------------------
function openTab(name) {
  if (TABS.indexOf(name) === -1) name = 'overview';

  var buttons = document.querySelectorAll('#tabs button');
  for (var i = 0; i < buttons.length; i++) {
    buttons[i].className = (buttons[i].getAttribute('data-tab') === name) ? 'active' : '';
  }
  TABS.forEach(function (t) {
    el('tab-' + t).className = 'tab-panel' + (t === name ? ' show' : '');
  });
  history.replaceState(null, '', '#' + name);
}

el('tabs').addEventListener('click', function (e) {
  var name = e.target.getAttribute('data-tab');
  if (name) openTab(name);
});

// ---------------------------------------------------------------
// overview
// ---------------------------------------------------------------
function overviewHtml() {
  var s = student;

  return '<div class="box"><h3>Personal Information ' +
      '<button type="button" class="noprint" data-edit="personal">Edit</button></h3>' +
      detailsTable([
        ['Full Name', s.name],
        ['Roll Number', s.rollNo],
        ['Register No', s.registerNo],
        ['Department', s.dept],
        ['Course', s.course],
        ['Section', s.section],
        ['Date of Birth', s.dob ? formatDate(s.dob) : null],
        ['Gender', s.gender],
        ['Email', s.email],
        ['Phone', s.phone]
      ]) + '</div>' +

    '<div class="box"><h3>Academic Information</h3>' +
      detailsTable([
        ['Academic Year', s.academicYear],
        ['Current Semester', s.semester],
        ['Admission Year', s.admissionYear],
        ['Course Duration', s.courseDuration]
      ]) + '</div>' +

    '<div class="box"><h3>Contact / Emergency ' +
      '<button type="button" class="noprint" data-edit="contact">Edit</button></h3>' +
      detailsTable([
        ['Parent / Guardian', s.guardianName],
        ['Emergency Contact', s.emergencyContact],
        ['Parent Phone', s.guardianPhone],
        ['Relationship', s.relationship]
      ]) + '</div>' +

    '<div class="box"><h3>Additional Details</h3>' +
      detailsTable([
        ['Blood Group', s.bloodGroup],
        ['Category', s.category],
        ['Address', s.address],
        ['Languages Known', s.languages]
      ]) + '</div>';
}

// ---------------------------------------------------------------
// academic
// ---------------------------------------------------------------
function academicHtml() {
  var s = student;
  var stats = s.stats || {};
  var subjects = s.subjects || [];
  var semesters = s.semesters || [];

  var html = '<div class="box"><h3>Summary</h3>' + detailsTable([
    ['CGPA', num(stats.cgpa, 2)],
    ['Credits Earned', num(stats.credits)],
    ['Backlogs', num(stats.backlogs)],
    ['Class Rank', stats.rank ? '#' + stats.rank : null]
  ]) + '</div>';

  html += '<div class="box"><h3>Current Semester Subjects (Semester ' + roman(s.semester) + ')</h3>';
  if (subjects.length === 0) {
    html += '<p>No marks recorded for this semester.</p>';
  } else {
    html += '<table><thead><tr><th>Code</th><th>Subject</th><th class="num">Marks</th><th>Grade</th><th>Status</th></tr></thead><tbody>';
    subjects.forEach(function (sub) {
      html += '<tr><td>' + esc(sub.code) + '</td><td>' + esc(sub.name) + '</td>' +
        '<td class="num">' + num(sub.marks, 1) + ' / 100</td>' +
        '<td>' + show(sub.grade) + '</td><td>' + show(sub.status) + '</td></tr>';
    });
    html += '</tbody></table>';
  }
  html += '</div>';

  html += '<div class="box"><h3>Semester-wise SGPA</h3>';
  if (semesters.length === 0) {
    html += '<p>No semester data yet.</p>';
  } else {
    html += '<table class="small"><thead><tr><th>Semester</th><th class="num">SGPA</th><th class="num">Percentage</th><th class="num">Credits</th><th>Status</th></tr></thead><tbody>';
    semesters.forEach(function (r) {
      html += '<tr><td>' + esc(r.semester) + '</td><td class="num">' + num(r.sgpa, 2) + '</td>' +
        '<td class="num">' + num(r.percentage, 1) + '%</td><td class="num">' + num(r.credits) + '</td>' +
        '<td>' + show(r.status) + '</td></tr>';
    });
    html += '</tbody></table>';
  }
  return html + '</div>';
}

// ---------------------------------------------------------------
// attendance
// ---------------------------------------------------------------
function attendanceHtml() {
  var s = student;
  var stats = s.stats || {};
  var bySem = s.attendanceBySemester || [];
  var monthly = s.attendanceMonthly || [];

  var html = '<div class="box"><h3>Overall Attendance: ' + num(stats.attendance, 0) + '%</h3>';

  var low = bySem.some(function (r) { return r.percentage < 75; });
  if (low) {
    html += '<p class="message error">Attendance is below 75% in one or more semesters (75% is the minimum needed for exams).</p>';
  }
  html += '</div>';

  html += '<div class="box"><h3>Semester-wise Attendance</h3>';
  if (bySem.length === 0) {
    html += '<p>No attendance recorded.</p>';
  } else {
    html += '<table class="small"><thead><tr><th>Semester</th><th class="num">Attendance</th></tr></thead><tbody>';
    bySem.forEach(function (r) {
      html += '<tr><td>' + esc(r.semester) + '</td><td class="num">' + num(r.percentage, 1) + '%</td></tr>';
    });
    html += '</tbody></table>';
  }
  html += '</div>';

  html += '<div class="box"><h3>Monthly Attendance</h3>';
  if (monthly.length === 0) {
    html += '<p>No monthly data yet.</p>';
  } else {
    html += '<table class="small"><thead><tr><th>Month</th><th class="num">Attendance</th></tr></thead><tbody>';
    monthly.forEach(function (r) {
      html += '<tr><td>' + esc(r.month) + '</td><td class="num">' + num(r.percentage, 1) + '%</td></tr>';
    });
    html += '</tbody></table>';
  }
  return html + '</div>';
}

// ---------------------------------------------------------------
// results
// ---------------------------------------------------------------
function resultsHtml() {
  var results = student.results || [];

  if (results.length === 0) {
    return '<div class="box"><h3>Examination Results</h3><p>Results for semester ' +
      roman(student.semester) + ' are not published yet.</p></div>';
  }

  var options = '';
  results.forEach(function (r, i) {
    options += '<option value="' + esc(r.semester) + '"' + (i === results.length - 1 ? ' selected' : '') +
      '>Semester ' + roman(r.semester) + '</option>';
  });

  return '<div class="box"><h3>Examination Results</h3>' +
    '<p class="noprint">Semester: <select id="resultSem">' + options + '</select> ' +
    '<button type="button" id="printBtn">Print</button></p>' +
    '<table><thead><tr><th>Subject</th><th class="num">Marks</th><th>Grade</th><th>Status</th></tr></thead>' +
    '<tbody id="resultBody"></tbody></table>' +
    '<p id="resultTotals"></p></div>';
}

function showResultsForSemester() {
  var select = el('resultSem');
  if (!select) return;

  var results = student.results;
  var row = null;
  results.forEach(function (r) {
    if (String(r.semester) === select.value) row = r;
  });
  if (!row) return;

  var html = '';
  var subjects = row.subjects || [];
  if (subjects.length === 0) {
    html = '<tr><td colspan="4">No marks recorded for this semester.</td></tr>';
  }
  subjects.forEach(function (sub) {
    html += '<tr><td>' + esc(sub.name) + '</td><td class="num">' + num(sub.marks, 1) + ' / 100</td>' +
      '<td>' + show(sub.grade) + '</td><td>' + show(sub.status) + '</td></tr>';
  });
  el('resultBody').innerHTML = html;

  el('resultTotals').innerHTML =
    'Total: <b>' + num(row.total, 1) + '</b> | Percentage: <b>' + num(row.percentage, 1) + '%</b> | ' +
    'Grade: <b>' + show(row.grade) + '</b> | Rank: <b>' + (row.rank ? '#' + row.rank : '-') + '</b>';
}

// the results tab is rebuilt with the profile, so listen on the container
el('tab-results').addEventListener('change', function (e) {
  if (e.target.id === 'resultSem') showResultsForSemester();
});

el('tab-results').addEventListener('click', function (e) {
  if (e.target.id === 'printBtn') window.print();
});

// ---------------------------------------------------------------
// documents
// ---------------------------------------------------------------
function documentsHtml() {
  var docs = student.documents || [];
  if (docs.length === 0) {
    return '<div class="box"><h3>Documents</h3><p>No documents uploaded.</p></div>';
  }

  var html = '<div class="box"><h3>Documents</h3><table><thead><tr><th>Name</th><th>Type</th><th>Uploaded</th><th>Status</th></tr></thead><tbody>';
  docs.forEach(function (d) {
    html += '<tr><td>' + esc(d.name) + '</td><td>' + esc(d.type) + '</td><td>' +
      formatDate(d.uploadedOn) + '</td><td>' + esc(d.status) + '</td></tr>';
  });
  return html + '</tbody></table></div>';
}

// ---------------------------------------------------------------
// edit personal / contact details
// ---------------------------------------------------------------
function openEdit(section) {
  var config = editConfig[section];
  if (!config) return;
  editSection = section;

  var html = '<div class="form-grid">';
  config.fields.forEach(function (f) {
    var value = student[f.key];
    if (value === null || value === undefined) value = '';

    html += '<label>' + esc(f.label);
    if (f.type === 'select') {
      html += '<select name="' + f.key + '">';
      f.options.forEach(function (opt) {
        html += '<option value="' + esc(opt) + '"' + (opt === value ? ' selected' : '') + '>' + (opt || 'Not specified') + '</option>';
      });
      html += '</select>';
    } else {
      html += '<input type="' + f.type + '" name="' + f.key + '" value="' + esc(value) + '">';
    }
    html += '</label>';
  });
  html += '</div>';

  el('editTitle').textContent = config.title;
  el('editForm').innerHTML = html;
  showMessage('editMessage', '');
  el('editBox').style.display = 'block';
  el('editBox').scrollIntoView();
}

function closeEdit() {
  el('editBox').style.display = 'none';
}

function saveEdit() {
  var config = editConfig[editSection];
  var payload = { id: student.id };

  for (var i = 0; i < config.fields.length; i++) {
    var f = config.fields[i];
    var value = el('editForm').elements[f.key].value.trim();
    if (f.check && !f.check.test(value)) {
      showMessage('editMessage', f.error, 'error');
      return;
    }
    payload[f.key] = value;
  }

  var btn = el('editSave');
  btn.disabled = true;

  API.updateStudent(payload).then(function (res) {
    if (res && res.student) {
      for (var key in res.student) student[key] = res.student[key];
    }
    closeEdit();
    showProfile();
    showMessage('profileMessage', (res && res.message) || 'Profile updated.', 'ok');
  }).catch(function (err) {
    showMessage('editMessage', 'Update failed: ' + err.message, 'error');
  }).then(function () {
    btn.disabled = false;
  });
}

el('tab-overview').addEventListener('click', function (e) {
  var section = e.target.getAttribute('data-edit');
  if (section) openEdit(section);
});

el('editSave').addEventListener('click', saveEdit);
el('editCancel').addEventListener('click', closeEdit);

// ---------------------------------------------------------------
// photo upload
// ---------------------------------------------------------------
el('photoInput').addEventListener('change', function () {
  var input = this;
  var file = input.files && input.files[0];
  if (!file) return;

  if (file.size > 2 * 1024 * 1024) {
    showMessage('profileMessage', 'Photo must be 2MB or smaller.', 'error');
    input.value = '';
    return;
  }

  API.uploadStudentPhoto(studentId, file).then(function (res) {
    student.photo = res.photo;
    el('photo').src = res.photo;
    showMessage('profileMessage', res.message || 'Photo updated.', 'ok');
  }).catch(function (err) {
    showMessage('profileMessage', 'Photo upload failed: ' + err.message, 'error');
  }).then(function () {
    input.value = '';
  });
});

// ---------------------------------------------------------------
// start
// ---------------------------------------------------------------
loadProfile();
openTab(window.location.hash.replace('#', ''));
