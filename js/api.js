// api.js - talks to the PHP files in the api/ folder

var API = {
  baseUrl: 'api/',

  // basic request, returns parsed json or throws an Error with the server message
  request: function (endpoint, options) {
    return fetch(this.baseUrl + endpoint, options || {}).then(function (response) {
      if (!response.ok) {
        return response.json().then(function (data) {
          throw new Error(data && data.message ? data.message : 'HTTP ' + response.status);
        }, function () {
          throw new Error('HTTP ' + response.status + ' ' + response.statusText);
        });
      }
      return response.json();
    });
  },

  // turn {a: 1, b: ''} into "a=1", skipping empty values
  query: function (filters, skipAll) {
    var params = new URLSearchParams();
    for (var key in filters) {
      var val = filters[key];
      if (val === undefined || val === null || val === '') continue;
      if (skipAll && val === 'All') continue;
      params.append(key, val);
    }
    return params.toString();
  },

  post: function (endpoint, data) {
    return this.request(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
  },

  getStats: function () {
    return this.request('stats.php');
  },

  getTopPerformers: function () {
    return this.request('top-performers.php');
  },

  getStudents: function (filters) {
    var q = this.query(filters || {});
    return this.request('students.php' + (q ? '?' + q : ''));
  },

  getStudent: function (id) {
    return this.request('student.php?id=' + encodeURIComponent(id));
  },

  getDepartments: function () {
    return this.request('departments.php');
  },

  getSemesters: function (semesterId) {
    var url = 'semesters.php?withSubjects=1';
    if (semesterId > 0) url += '&semesterId=' + semesterId;
    return this.request(url);
  },

  getResults: function (filters) {
    var q = this.query(filters || {});
    return this.request('results.php' + (q ? '?' + q : ''));
  },

  getAnalytics: function (filters) {
    var q = this.query(filters || {});
    return this.request('analytics.php' + (q ? '?' + q : ''));
  },

  getExportUrl: function (filters) {
    var q = this.query(filters || {}, true);
    return this.baseUrl + 'export.php' + (q ? '?' + q : '');
  },

  importStudents: function (file) {
    var formData = new FormData();
    formData.append('file', file);
    return this.request('import.php', { method: 'POST', body: formData });
  },

  // students
  createStudent: function (data) {
    return this.post('create-student.php', data);
  },

  updateStudentFull: function (id, data) {
    data.id = id;
    return this.post('update-student.php', data);
  },

  // used by the profile page (personal / contact edit)
  updateStudent: function (data) {
    return this.post('student-update.php', data);
  },

  deleteStudent: function (id, rollNo) {
    return this.post('delete-student.php', { id: id, confirmRollNo: rollNo });
  },

  saveAvatar: function (id, url) {
    return this.post('avatar.php', { id: id, avatar: url });
  },

  uploadStudentPhoto: function (id, file) {
    var formData = new FormData();
    formData.append('id', id);
    formData.append('photo', file);
    return this.request('student-photo.php', { method: 'POST', body: formData });
  },

  // attendance
  getAttendanceRoster: function (semesterId) {
    return this.request('attendance.php?action=roster&semesterId=' + semesterId);
  },

  getAttendanceSessions: function (filters) {
    filters.action = 'sessions';
    return this.request('attendance.php?' + this.query(filters));
  },

  getSessionRecords: function (sessionId) {
    return this.request('attendance.php?action=history&sessionId=' + encodeURIComponent(sessionId) + '&perPage=500');
  },

  markAttendance: function (data) {
    return this.post('mark-attendance.php', data);
  },

  deleteAttendanceSession: function (sessionId) {
    return this.post('delete-session.php', { sessionId: sessionId, onlyLegacy: false });
  }
};
