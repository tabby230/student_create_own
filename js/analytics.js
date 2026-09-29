// analytics.js - charts (Chart.js) and grade table

var trendChart = null;
var deptChart = null;

// no animations, keep the charts plain
if (typeof Chart !== 'undefined') {
  Chart.defaults.animation = false;
}

function currentFilters() {
  return {
    dept: document.getElementById('anDept').value,
    year: document.getElementById('anYear').value
  };
}

function showGradeTable(dist) {
  var html = '';
  dist.labels.forEach(function (label, i) {
    html += '<tr><td>' + esc(label) + '</td><td class="num">' + esc(dist.counts[i]) + '</td></tr>';
  });
  document.querySelector('#gradeTable tbody').innerHTML = html;
}

function drawTrend(trends) {
  if (trendChart) trendChart.destroy();

  trendChart = new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: trends.labels,
      datasets: [
        { label: 'Average attendance %', data: trends.averages, borderColor: '#1a4f9c', backgroundColor: '#1a4f9c' },
        { label: 'Present rate %', data: trends.passRates, borderColor: '#2c7a2c', backgroundColor: '#2c7a2c' }
      ]
    },
    options: {
      maintainAspectRatio: false,
      scales: { y: { min: 0, max: 100 } }
    }
  });
}

function drawDepartments(list) {
  if (deptChart) deptChart.destroy();

  deptChart = new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
      labels: list.map(function (d) { return d.name; }),
      datasets: [{
        label: 'Average score %',
        data: list.map(function (d) { return d.averageScore; }),
        backgroundColor: '#1a4f9c'
      }]
    },
    options: {
      maintainAspectRatio: false,
      scales: { y: { min: 0, max: 100 } }
    }
  });
}

function loadAnalytics() {
  API.getAnalytics(currentFilters()).then(function (data) {
    if (typeof Chart !== 'undefined') showMessage('analyticsMessage', '');
    showGradeTable(data.gradeDistribution);
    if (typeof Chart !== 'undefined') drawTrend(data.monthlyTrends);
  }).catch(function (err) {
    showMessage('analyticsMessage', 'Could not load analytics: ' + err.message, 'error');
  });
}

function loadDepartmentChart() {
  API.getDepartments().then(function (data) {
    if (typeof Chart !== 'undefined') drawDepartments(listFrom(data, 'departments'));
  }).catch(function (err) {
    console.log('department chart failed', err);
  });
}

document.getElementById('anDept').addEventListener('change', loadAnalytics);
document.getElementById('anYear').addEventListener('change', loadAnalytics);

if (typeof Chart === 'undefined') {
  showMessage('analyticsMessage', 'The chart library could not be loaded, only the table is shown.', 'error');
}

fillDepartments('anDept');
loadAnalytics();
loadDepartmentChart();
