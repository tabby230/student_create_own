// home.js - stats and top performers on the home page

function loadStats() {
  API.getStats().then(function (stats) {
    document.getElementById('totalStudents').textContent = stats.totalStudents;
    document.getElementById('totalDepartments').textContent = stats.totalDepartments;
    document.getElementById('averageScore').textContent = stats.averageScore + '%';
    document.getElementById('passRate').textContent = stats.passRate + '%';
  }).catch(function (err) {
    showMessage('homeMessage', 'Could not load statistics: ' + err.message, 'error');
  });
}

function loadTopPerformers() {
  var tbody = document.getElementById('performers');

  API.getTopPerformers().then(function (list) {
    if (!list || list.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6">No results yet.</td></tr>';
      return;
    }

    var html = '';
    list.forEach(function (p) {
      html += '<tr>' +
        '<td>' + esc(p.rank) + '</td>' +
        '<td><img class="thumb" src="' + esc(p.avatar) + '" alt=""> ' + esc(p.name) + '</td>' +
        '<td>' + esc(p.department) + '</td>' +
        '<td>' + esc(p.semester) + '</td>' +
        '<td>' + esc(p.score) + '</td>' +
        '<td>' + esc(p.cgpa) + '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
  }).catch(function (err) {
    tbody.innerHTML = '<tr><td colspan="6">Could not load top performers: ' + esc(err.message) + '</td></tr>';
  });
}

loadStats();
loadTopPerformers();
