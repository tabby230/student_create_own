// students.js - student list, add / edit / delete, csv import

var students = [];       // everything the api returned for the current filters
var currentPage = 1;
var perPage = 12;
var editingId = 0;       // 0 = adding a new student
var originalAvatar = '';
var semesters = [];      // from the database, used in the form
var departments = [];

var form = document.getElementById('studentForm');

// ---------------------------------------------------------------
// list
// ---------------------------------------------------------------
function loadStudents() {
  var filters = {
    search: document.getElementById('searchBox').value.trim(),
    dept: document.getElementById('deptFilter').value,
    year: document.getElementById('yearFilter').value,
    semester: document.getElementById('semesterFilter').value
  };

  API.getStudents(filters).then(function (data) {
    students = data;
    currentPage = 1;
    showStudents();
  }).catch(function (err) {
    document.getElementById('studentRows').innerHTML =
      '<tr><td colspan="8">Could not load students: ' + esc(err.message) + '</td></tr>';
  });
}

function showStudents() {
  var tbody = document.getElementById('studentRows');
  var total = students.length;
  var pages = Math.ceil(total / perPage) || 1;
  if (currentPage > pages) currentPage = pages;

  var start = (currentPage - 1) * perPage;
  var end = Math.min(start + perPage, total);

  if (total === 0) {
    tbody.innerHTML = '<tr><td colspan="8">No students found.</td></tr>';
    document.getElementById('pagerInfo').textContent = '';
    document.getElementById('pagerButtons').innerHTML = '';
    return;
  }

  var html = '';
  for (var i = start; i < end; i++) {
    var s = students[i];
    html += '<tr>' +
      '<td>' + esc(s.rollNo) + '</td>' +
      '<td><a href="student-profile.html?id=' + s.id + '">' + esc(s.name) + '</a></td>' +
      '<td>' + esc(s.dept) + '</td>' +
      '<td>' + esc(s.year) + '</td>' +
      '<td>' + esc(s.semester) + '</td>' +
      '<td class="num">' + esc(s.attendance) + '%</td>' +
      '<td class="num">' + (s.marks === null ? '-' : esc(s.marks) + '%') + '</td>' +
      '<td>' +
        '<a href="student-profile.html?id=' + s.id + '">View</a> | ' +
        '<a href="#" data-edit="' + s.id + '">Edit</a> | ' +
        '<a href="#" data-delete="' + s.id + '" data-roll="' + esc(s.rollNo) + '">Delete</a>' +
      '</td>' +
      '</tr>';
  }
  tbody.innerHTML = html;

  document.getElementById('pagerInfo').textContent =
    'Showing ' + (start + 1) + ' - ' + end + ' of ' + total + ' students. ';
  showPager(pages);
}

function showPager(pages) {
  var html = '';
  html += '<button type="button" data-page="' + (currentPage - 1) + '"' + (currentPage === 1 ? ' disabled' : '') + '>Prev</button>';

  for (var p = 1; p <= pages; p++) {
    // only show the first, last and pages near the current one
    if (p === 1 || p === pages || Math.abs(p - currentPage) <= 1) {
      html += '<button type="button" data-page="' + p + '"' + (p === currentPage ? ' class="current"' : '') + '>' + p + '</button>';
    } else if (Math.abs(p - currentPage) === 2) {
      html += ' ... ';
    }
  }

  html += '<button type="button" data-page="' + (currentPage + 1) + '"' + (currentPage === pages ? ' disabled' : '') + '>Next</button>';
  document.getElementById('pagerButtons').innerHTML = html;
}

// ---------------------------------------------------------------
// add / edit form
// ---------------------------------------------------------------
function loadFormLists() {
  return Promise.all([API.getDepartments(), API.getSemesters()]).then(function (results) {
    departments = listFrom(results[0], 'departments');
    semesters = listFrom(results[1], 'semesters');

    var deptHtml = '';
    departments.forEach(function (d) {
      deptHtml += '<option value="' + esc(d.name) + '">' + esc(d.name) + '</option>';
    });
    document.getElementById('formDept').innerHTML = deptHtml;

    var semHtml = '';
    semesters.forEach(function (s) {
      semHtml += '<option value="' + s.id + '">Semester ' + s.semesterNumber +
        (s.academicYear ? ' (' + esc(s.academicYear) + ')' : '') + '</option>';
    });
    document.getElementById('formSemester').innerHTML = semHtml;
  }).catch(function (err) {
    console.log('could not load form lists', err);
  });
}

function setField(name, value) {
  var field = form.elements[name];
  if (field) field.value = (value === null || value === undefined) ? '' : value;
}

function openForm(id) {
  editingId = id;
  originalAvatar = '';
  form.reset();
  showMessage('formMessage', '');

  var box = document.getElementById('formBox');
  document.getElementById('formTitle').textContent = id ? 'Edit Student' : 'Add Student';
  document.getElementById('avatarRow').style.display = id ? 'block' : 'none';
  box.style.display = 'block';
  box.scrollIntoView();

  if (!id) return;

  // editing: load the full record from the database
  API.getStudent(id).then(function (s) {
    setField('name', s.name);
    setField('rollNo', s.rollNo);
    setField('email', s.email);
    setField('phone', s.phone);
    setField('department', s.dept);
    setField('year', s.year);
    setField('section', s.section);
    setField('course', s.course);
    setField('dob', s.dob);
    setField('gender', s.gender);
    setField('category', s.category);
    setField('bloodGroup', s.bloodGroup);
    setField('address', s.address);
    setField('guardianName', s.guardianName);
    setField('guardianPhone', s.guardianPhone);
    setField('emergencyContact', s.emergencyContact);
    setField('relationship', s.relationship);
    setField('languages', s.languages);
    setField('avatar', s.avatar);
    originalAvatar = s.avatar || '';

    // the form stores the semester id, the student record has the number
    semesters.forEach(function (sem) {
      if (sem.semesterNumber === s.semester) setField('semesterId', sem.id);
    });
  }).catch(function (err) {
    showMessage('formMessage', 'Could not load student: ' + err.message, 'error');
  });
}

function closeForm() {
  document.getElementById('formBox').style.display = 'none';
  editingId = 0;
}

function fieldValue(name) {
  return form.elements[name].value.trim();
}

function saveStudent(event) {
  event.preventDefault();

  var data = {
    name: fieldValue('name'),
    rollNo: fieldValue('rollNo'),
    email: fieldValue('email'),
    phone: fieldValue('phone'),
    department: fieldValue('department'),
    semesterId: fieldValue('semesterId'),
    year: fieldValue('year'),
    section: fieldValue('section'),
    course: fieldValue('course'),
    dob: fieldValue('dob'),
    gender: fieldValue('gender'),
    category: fieldValue('category'),
    bloodGroup: fieldValue('bloodGroup'),
    address: fieldValue('address'),
    guardianName: fieldValue('guardianName'),
    guardianPhone: fieldValue('guardianPhone'),
    emergencyContact: fieldValue('emergencyContact'),
    relationship: fieldValue('relationship'),
    languages: fieldValue('languages')
  };

  if (!data.name || !data.rollNo) {
    showMessage('formMessage', 'Name and roll number are required.', 'error');
    return;
  }

  var saveBtn = document.getElementById('saveBtn');
  saveBtn.disabled = true;

  var request;
  if (editingId) {
    request = API.updateStudentFull(editingId, data).then(function () {
      var newAvatar = fieldValue('avatar');
      if (newAvatar !== originalAvatar) {
        return API.saveAvatar(editingId, newAvatar);
      }
    });
  } else {
    request = API.createStudent(data);
  }

  request.then(function () {
    var wasEditing = editingId;
    closeForm();
    showMessage('studentsMessage', wasEditing ? 'Student updated.' : 'Student added.', 'ok');
    loadStudents();
  }).catch(function (err) {
    showMessage('formMessage', 'Save failed: ' + err.message, 'error');
  }).then(function () {
    saveBtn.disabled = false;
  });
}

// ---------------------------------------------------------------
// delete
// ---------------------------------------------------------------
function deleteStudent(id, rollNo) {
  var typed = prompt('This will delete student ' + rollNo + ' along with their marks and attendance.\nType the roll number to confirm:');
  if (typed === null) return;
  if (typed.trim() !== String(rollNo)) {
    alert('Roll number did not match. Nothing was deleted.');
    return;
  }

  API.deleteStudent(id).then(function () {
    showMessage('studentsMessage', 'Student deleted.', 'ok');
    loadStudents();
  }).catch(function (err) {
    showMessage('studentsMessage', 'Delete failed: ' + err.message, 'error');
  });
}

// ---------------------------------------------------------------
// csv import
// ---------------------------------------------------------------
function importCsv() {
  var file = document.getElementById('csvFile').files[0];
  if (!file) {
    showMessage('studentsMessage', 'Choose a CSV file first.', 'error');
    return;
  }

  var btn = document.getElementById('importBtn');
  btn.disabled = true;

  API.importStudents(file).then(function (res) {
    showMessage('studentsMessage', res.message || 'Students imported.', 'ok');
    document.getElementById('csvFile').value = '';
    loadStudents();
  }).catch(function (err) {
    showMessage('studentsMessage', 'Import failed: ' + err.message, 'error');
  }).then(function () {
    btn.disabled = false;
  });
}

// ---------------------------------------------------------------
// events
// ---------------------------------------------------------------
document.getElementById('searchBox').addEventListener('input', loadStudents);
document.getElementById('deptFilter').addEventListener('change', loadStudents);
document.getElementById('yearFilter').addEventListener('change', loadStudents);
document.getElementById('semesterFilter').addEventListener('change', loadStudents);

document.getElementById('perPage').addEventListener('change', function () {
  perPage = parseInt(this.value, 10);
  currentPage = 1;
  showStudents();
});

document.getElementById('addBtn').addEventListener('click', function () { openForm(0); });
document.getElementById('cancelBtn').addEventListener('click', closeForm);
document.getElementById('importBtn').addEventListener('click', importCsv);
form.addEventListener('submit', saveStudent);

document.getElementById('pagerButtons').addEventListener('click', function (e) {
  var page = e.target.getAttribute('data-page');
  if (page) {
    currentPage = parseInt(page, 10);
    showStudents();
  }
});

// edit / delete links in the table
document.getElementById('studentRows').addEventListener('click', function (e) {
  var editId = e.target.getAttribute('data-edit');
  var deleteId = e.target.getAttribute('data-delete');
  if (editId) {
    e.preventDefault();
    openForm(parseInt(editId, 10));
  }
  if (deleteId) {
    e.preventDefault();
    deleteStudent(parseInt(deleteId, 10), e.target.getAttribute('data-roll'));
  }
});

// ---------------------------------------------------------------
// start
// ---------------------------------------------------------------
var params = new URLSearchParams(window.location.search);
if (params.get('search')) {
  document.getElementById('searchBox').value = params.get('search');
}

Promise.all([
  fillDepartments('deptFilter'),
  fillSemesters('semesterFilter'),
  loadFormLists()
]).then(function () {
  // ?dept=Physics comes from the departments page
  var dept = params.get('dept');
  if (dept) {
    var select = document.getElementById('deptFilter');
    select.value = dept;
    if (select.value !== dept) select.value = 'All';
  }
  loadStudents();
});
