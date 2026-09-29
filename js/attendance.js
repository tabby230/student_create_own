// attendance.js - mark attendance for a class and see past sessions

var STATUSES = ['present', 'late', 'absent', 'excused'];
var STATUS_LABELS = { present: 'Present', late: 'Late', absent: 'Absent', excused: 'Excused' };

var marks = {};          // studentId -> status
var rosterIds = [];      // student ids in the roster on screen
var sessionPage = 1;
var SESSIONS_PER_PAGE = 10;

function el(id) {
  return document.getElementById(id);
}

// ---------------------------------------------------------------
// dropdowns
// ---------------------------------------------------------------
function loadSemesters() {
  return API.getSemesters().then(function (data) {
    var html = '<option value="">Select semester</option>';
    listFrom(data, 'semesters').forEach(function (s) {
      html += '<option value="' + s.id + '">Semester ' + s.semesterNumber +
        (s.academicYear ? ' (' + esc(s.academicYear) + ')' : '') +
        ' - ' + s.studentCount + ' students</option>';
    });
    el('attSemester').innerHTML = html;
  }).catch(function (err) {
    showMessage('attMessage', 'Could not load semesters: ' + err.message, 'error');
  });
}

function loadSubjects() {
  var semesterId = parseInt(el('attSemester').value, 10) || 0;
  el('attSubject').innerHTML = '<option value="">No subject</option>';
  if (!semesterId) return;

  API.getSemesters(semesterId).then(function (data) {
    var html = '<option value="">No subject</option>';
    (data.subjects || []).forEach(function (s) {
      html += '<option value="' + s.id + '">' + esc(s.code) + ' - ' + esc(s.name) + '</option>';
    });
    el('attSubject').innerHTML = html;
  }).catch(function (err) {
    showMessage('attMessage', 'Could not load subjects: ' + err.message, 'error');
  });
}

// ---------------------------------------------------------------
// roster
// ---------------------------------------------------------------
function updateSummary() {
  var count = { present: 0, late: 0, absent: 0, excused: 0 };
  rosterIds.forEach(function (id) {
    if (count[marks[id]] !== undefined) count[marks[id]]++;
  });
  el('sumTotal').textContent = rosterIds.length;
  el('sumPresent').textContent = count.present;
  el('sumLate').textContent = count.late;
  el('sumAbsent').textContent = count.absent;
  el('sumExcused').textContent = count.excused;
}

// tick the radio buttons to match the marks object
function paintMarks() {
  var radios = document.querySelectorAll('#attRoster input[type="radio"]');
  for (var i = 0; i < radios.length; i++) {
    radios[i].checked = (marks[radios[i].getAttribute('data-id')] === radios[i].value);
  }
  updateSummary();
}

function loadRoster() {
  var semesterId = parseInt(el('attSemester').value, 10) || 0;
  if (!semesterId) {
    showMessage('attMessage', 'Select a semester first.', 'error');
    return;
  }

  showMessage('attMessage', '');
  el('attRoster').innerHTML = 'Loading...';

  API.getAttendanceRoster(semesterId).then(function (data) {
    var roster = data.roster || [];
    marks = {};
    rosterIds = roster.map(function (s) { return s.studentId; });

    if (roster.length === 0) {
      el('attSummary').style.display = 'none';
      el('attRoster').innerHTML = '<p>No students in this semester.</p>';
      return;
    }

    var html = '<table><thead><tr><th>Roll No</th><th>Name</th><th>Department</th><th>Attendance</th></tr></thead><tbody>';
    roster.forEach(function (s) {
      html += '<tr><td>' + esc(s.rollNo) + '</td><td>' + esc(s.name) + '</td><td>' + show(s.dept) + '</td><td class="att-row">';
      STATUSES.forEach(function (st) {
        html += '<label><input type="radio" name="student_' + s.studentId + '" value="' + st + '" data-id="' + s.studentId + '"> ' + STATUS_LABELS[st] + '</label>';
      });
      html += '</td></tr>';
    });
    html += '</tbody></table>';
    el('attRoster').innerHTML = html;
    el('attSummary').style.display = 'block';
    updateSummary();

    return loadExistingMarks(semesterId);
  }).catch(function (err) {
    el('attRoster').innerHTML = '';
    showMessage('attMessage', 'Could not load students: ' + err.message, 'error');
  });
}

// if this class was already marked on the chosen date, show what was saved
function loadExistingMarks(semesterId) {
  var date = el('attDate').value;
  var subjectId = parseInt(el('attSubject').value, 10) || 0;
  if (!date) return;

  return API.getAttendanceSessions({ semesterId: semesterId, subjectId: subjectId, date: date, perPage: 1 })
    .then(function (data) {
      var found = (data.sessions || [])[0];
      if (!found) return;

      return API.getSessionRecords(found.sessionId).then(function (recs) {
        (recs.records || []).forEach(function (r) { marks[r.studentId] = r.status; });
        paintMarks();
        showMessage('attMessage', 'Attendance for ' + date + ' was already saved. You can change it and save again.', '');
      });
    }).catch(function () {
      // nothing saved for this date, that is normal
    });
}

// ---------------------------------------------------------------
// save
// ---------------------------------------------------------------
function saveAttendance() {
  var semesterId = parseInt(el('attSemester').value, 10) || 0;
  var date = el('attDate').value;

  if (!semesterId || !date) {
    showMessage('attMessage', 'Select a semester and a date.', 'error');
    return;
  }

  var records = [];
  rosterIds.forEach(function (id) {
    if (marks[id]) records.push({ studentId: parseInt(id, 10), status: marks[id] });
  });

  if (records.length === 0) {
    showMessage('attMessage', 'Mark at least one student.', 'error');
    return;
  }

  var btn = el('saveAttBtn');
  btn.disabled = true;

  API.markAttendance({
    semesterId: semesterId,
    subjectId: parseInt(el('attSubject').value, 10) || 0,
    sessionDate: date,
    takenBy: el('attTakenBy').value.trim(),
    records: records
  }).then(function (res) {
    showMessage('attMessage', 'Saved ' + (res.markedCount || records.length) + ' records.', 'ok');
    sessionPage = 1;
    loadSessions();
  }).catch(function (err) {
    showMessage('attMessage', 'Save failed: ' + err.message, 'error');
  }).then(function () {
    btn.disabled = false;
  });
}

// ---------------------------------------------------------------
// past sessions
// ---------------------------------------------------------------
function loadSessions() {
  var box = el('attSessions');

  API.getAttendanceSessions({
    page: sessionPage,
    perPage: SESSIONS_PER_PAGE,
    source: el('sourceFilter').value
  }).then(function (data) {
    var sessions = data.sessions || [];

    if (sessions.length === 0) {
      box.innerHTML = '<p>No sessions found.</p>';
      el('attPager').innerHTML = '';
      return;
    }

    var html = '<table><thead><tr><th>Date</th><th>Sem</th><th>Subject</th><th>Source</th>' +
      '<th>Present</th><th>Absent</th><th>Late</th><th>Excused</th><th>%</th><th>Taken by</th><th></th></tr></thead><tbody>';

    sessions.forEach(function (s) {
      html += '<tr>' +
        '<td>' + esc(s.sessionDate) + '</td>' +
        '<td>' + esc(s.semester) + '</td>' +
        '<td>' + show(s.subjectCode) + '</td>' +
        '<td>' + esc(s.source) + '</td>' +
        '<td>' + esc(s.present) + '</td>' +
        '<td>' + esc(s.absent) + '</td>' +
        '<td>' + esc(s.late) + '</td>' +
        '<td>' + esc(s.excused) + '</td>' +
        '<td>' + (s.percentage === null ? '-' : esc(s.percentage) + '%') + '</td>' +
        '<td>' + show(s.takenBy) + '</td>' +
        '<td>' + (s.source === 'legacy' ? '' : '<a href="#" data-delete="' + s.sessionId + '">Delete</a>') + '</td>' +
        '</tr>';
    });
    html += '</tbody></table>';
    box.innerHTML = html;

    var pages = Math.max(1, Math.ceil((data.total || 0) / SESSIONS_PER_PAGE));
    el('attPager').innerHTML =
      'Page ' + data.page + ' of ' + pages + ' (' + data.total + ' sessions) ' +
      '<button type="button" data-go="' + (data.page - 1) + '"' + (data.page <= 1 ? ' disabled' : '') + '>Prev</button> ' +
      '<button type="button" data-go="' + (data.page + 1) + '"' + (data.page >= pages ? ' disabled' : '') + '>Next</button>';
  }).catch(function (err) {
    box.innerHTML = '<p>Could not load sessions: ' + esc(err.message) + '</p>';
  });
}

// ---------------------------------------------------------------
// events
// ---------------------------------------------------------------
el('attSemester').addEventListener('change', loadSubjects);
el('loadRosterBtn').addEventListener('click', loadRoster);
el('saveAttBtn').addEventListener('click', saveAttendance);
el('refreshBtn').addEventListener('click', loadSessions);

el('sourceFilter').addEventListener('change', function () {
  sessionPage = 1;
  loadSessions();
});

el('allPresentBtn').addEventListener('click', function () {
  rosterIds.forEach(function (id) { marks[id] = 'present'; });
  paintMarks();
});

el('allAbsentBtn').addEventListener('click', function () {
  rosterIds.forEach(function (id) { marks[id] = 'absent'; });
  paintMarks();
});

el('clearBtn').addEventListener('click', function () {
  marks = {};
  paintMarks();
});

// a radio button was clicked
el('attRoster').addEventListener('change', function (e) {
  var id = e.target.getAttribute('data-id');
  if (id) {
    marks[id] = e.target.value;
    updateSummary();
  }
});

el('attPager').addEventListener('click', function (e) {
  var go = e.target.getAttribute('data-go');
  if (go) {
    sessionPage = parseInt(go, 10);
    loadSessions();
  }
});

el('attSessions').addEventListener('click', function (e) {
  var id = e.target.getAttribute('data-delete');
  if (!id) return;
  e.preventDefault();
  if (!confirm('Delete session ' + id + ' and all its attendance records?')) return;

  API.deleteAttendanceSession(parseInt(id, 10)).then(function () {
    loadSessions();
  }).catch(function (err) {
    alert('Delete failed: ' + err.message);
  });
});

// ---------------------------------------------------------------
// start
// ---------------------------------------------------------------
el('attDate').value = new Date().toISOString().slice(0, 10);
loadSemesters();
loadSessions();
