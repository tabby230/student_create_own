// results.js - results table with filters and grade summary

var allResults = [];

function loadResults() {
  API.getResults().then(function (data) {
    allResults = data;
    showGradeSummary();
    showResults();
  }).catch(function (err) {
    document.getElementById('resultRows').innerHTML = '';
    showMessage('resultsMessage', 'Could not load results: ' + err.message, 'error');
  });
}

function showGradeSummary() {
  var grades = [
    ['O', '95% and above'],
    ['A+', '90 - 94%'],
    ['A', '80 - 89%'],
    ['B+', '70 - 79%'],
    ['B', '60 - 69%'],
    ['C', 'below 60%']
  ];

  var html = '';
  grades.forEach(function (g) {
    var count = allResults.filter(function (r) { return r.grade === g[0]; }).length;
    html += '<tr><td>' + g[0] + '</td><td>' + g[1] + '</td><td class="num">' + count + '</td></tr>';
  });
  html += '<tr><th colspan="2">Total</th><th class="num">' + allResults.length + '</th></tr>';
  document.querySelector('#gradeSummary tbody').innerHTML = html;
}

function showResults() {
  var q = document.getElementById('resultSearch').value.toLowerCase();
  var dept = document.getElementById('resultDept').value;
  var sem = document.getElementById('resultSemester').value;
  var grade = document.getElementById('resultGrade').value;

  var rows = allResults.filter(function (r) {
    if (q && r.name.toLowerCase().indexOf(q) === -1 &&
        r.rollNo.toLowerCase().indexOf(q) === -1 &&
        r.dept.toLowerCase().indexOf(q) === -1) return false;
    if (dept !== 'All' && r.dept !== dept) return false;
    if (sem !== 'All' && String(r.semester) !== sem) return false;
    if (grade !== 'All' && r.grade !== grade) return false;
    return true;
  });

  var tbody = document.getElementById('resultRows');
  if (rows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8">No matching results.</td></tr>';
    return;
  }

  var html = '';
  rows.forEach(function (r) {
    html += '<tr>' +
      '<td>' + esc(r.rank) + '</td>' +
      '<td>' + esc(r.rollNo) + '</td>' +
      '<td>' + esc(r.name) + '</td>' +
      '<td>' + esc(r.dept) + '</td>' +
      '<td>' + esc(r.semester) + '</td>' +
      '<td class="num">' + esc(r.marks) + ' / 100</td>' +
      '<td>' + esc(r.grade) + '</td>' +
      '<td>' + esc(r.status) + '</td>' +
      '</tr>';
  });
  tbody.innerHTML = html;
}

document.getElementById('resultSearch').addEventListener('input', showResults);
document.getElementById('resultDept').addEventListener('change', showResults);
document.getElementById('resultSemester').addEventListener('change', showResults);
document.getElementById('resultGrade').addEventListener('change', showResults);

document.getElementById('exportBtn').addEventListener('click', function () {
  window.location.href = API.getExportUrl({
    dept: document.getElementById('resultDept').value,
    semester: document.getElementById('resultSemester').value,
    grade: document.getElementById('resultGrade').value
  });
});

fillDepartments('resultDept');
fillSemesters('resultSemester');
loadResults();
