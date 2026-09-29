// departments.js - list of departments

API.getDepartments().then(function (data) {
  var list = listFrom(data, 'departments');
  var tbody = document.getElementById('deptRows');

  if (list.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7">No departments found.</td></tr>';
    return;
  }

  var html = '';
  list.forEach(function (d) {
    html += '<tr>' +
      '<td>' + esc(d.code) + '</td>' +
      '<td>' + esc(d.name) + '</td>' +
      '<td>' + show(d.hod) + '</td>' +
      '<td class="num">' + esc(d.facultyCount) + '</td>' +
      '<td class="num">' + esc(d.studentCount) + '</td>' +
      '<td class="num">' + (d.averageScore === null ? '-' : esc(d.averageScore) + '%') + '</td>' +
      '<td><a href="students.html?dept=' + encodeURIComponent(d.name) + '">View students</a></td>' +
      '</tr>';
  });
  tbody.innerHTML = html;
}).catch(function (err) {
  document.getElementById('deptRows').innerHTML = '';
  showMessage('deptMessage', 'Could not load departments: ' + err.message, 'error');
});
