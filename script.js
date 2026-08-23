const REMEMBER_KEY = 'drlcefiRememberedLogin';
let dashboardData = { students: [], schedules: [], attendance: [], audit: [], notificationSettings: {} };

function getSurname(name) {
    const parts = String(name).trim().split(/\s+/);
    return parts.length > 1 ? parts[parts.length - 1] : parts[0];
}

function setAuthMessage(message, type) {
    const messageElement = document.getElementById('authMessage');
    if (messageElement) {
        messageElement.textContent = message;
        messageElement.className = `auth-message ${type || ''}`;
    }
}

async function showApp(user) {
    document.getElementById('authScreen').hidden = true;
    document.getElementById('appShell').hidden = false;
    const welcomeTitle = document.getElementById('dashboard-title');
    const adminName = document.querySelector('.admin-btn span:nth-child(2)');
    const displayName = user && user.name ? user.name : 'Administrator';
    const role = user && user.role ? user.role : 'Administrator';

    if (welcomeTitle) {
        welcomeTitle.textContent = `Welcome, ${displayName}!`;
    }
    if (adminName) {
        adminName.textContent = `${displayName} (${role})`;
    }
    document.getElementById('appShell').dataset.role = role;
    applyRolePermissions(role);
    await loadDashboardData();
}

function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, function (character) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character];
    });
}

async function loadDashboardData() {
    dashboardData = await requestApi('dashboard');
    const studentsBody = document.querySelector('#studentTable tbody');
    if (studentsBody) {
        const sortedStudents = dashboardData.students.slice().sort(function (firstStudent, secondStudent) {
            const surnameOrder = getSurname(firstStudent.full_name).localeCompare(getSurname(secondStudent.full_name), undefined, { sensitivity: 'base' });
            return surnameOrder || String(firstStudent.full_name).localeCompare(String(secondStudent.full_name), undefined, { sensitivity: 'base' });
        });
        studentsBody.innerHTML = sortedStudents.map(function (student) {
            const archived = Number(student.is_archived) === 1;
            return `<tr data-student-status="${archived ? 'archived' : 'active'}">
                <td>${escapeHtml(student.student_id)}</td><td>${escapeHtml(student.full_name)}</td>
                <td>${escapeHtml(student.course)}</td><td>${escapeHtml(student.year)}</td><td>${escapeHtml(student.school_year || 'Unassigned')}</td>
                <td>${escapeHtml(student.status)}</td><td><details class="row-menu"><summary aria-label="Student actions">•••</summary><div class="row-menu__content"><button class="menu-item" type="button" onclick="openStudentProfile(this)">Profile</button></div></details></td></tr>`;
        }).join('');
        filterStudents();
        renderArchivedStudents();
    }
    const attendanceBody = document.getElementById('attendanceTableBody');
    if (attendanceBody) {
        attendanceBody.innerHTML = dashboardData.attendance.map(function (record) {
            return `<tr data-course="${escapeHtml(record.course)}" data-attendance-date="${attendanceDateKey(record.attendance_date)}"><td>${escapeHtml(record.student_id)}</td><td>${escapeHtml(record.student_name)}</td><td>${escapeHtml(record.attendance_date)}</td><td>${escapeHtml(record.subject)}</td><td>${escapeHtml(record.time_in)}</td><td>${escapeHtml(record.time_out)}</td><td>${escapeHtml(record.status)}</td><td><details class="row-menu"><summary aria-label="Attendance actions">•••</summary><div class="row-menu__content"><button class="menu-item" type="button">View</button></div></details></td></tr>`;
        }).join('');
        getAttendanceRows().forEach(function (row) { row.dataset.matchesFilter = 'true'; });
        renderAttendanceDateControls();
        renderAttendancePage();
    }
    const scheduleBody = document.getElementById('scheduleTableBody');
    if (scheduleBody) {
        scheduleBody.innerHTML = dashboardData.schedules.map(function (schedule) {
            return `<tr data-schedule-id="${escapeHtml(schedule.id)}"><td>${escapeHtml(schedule.subject)}</td><td>${escapeHtml(schedule.instructor)}</td><td>${escapeHtml(schedule.room)}</td><td>${escapeHtml(schedule.day)}</td><td>${escapeHtml(schedule.start_time)} - ${escapeHtml(schedule.end_time)}</td><td><details class="row-menu"><summary aria-label="Schedule actions">•••</summary><div class="row-menu__content"><button class="menu-item edit-btn" type="button" onclick="editRow(this)">Edit</button><button class="menu-item delete-btn" type="button" onclick="openDeleteModal(this)">Delete</button></div></details></td></tr>`;
        }).join('');
        applyScheduleFilters();
    }
    renderAuditLog();
    renderAttendanceHistory();
    updateOverviewStats();
    loadNotificationSettingsFromData();
    applyRolePermissions(document.getElementById('appShell').dataset.role || 'Administrator');
}

function showAuth() {
    document.getElementById('authScreen').hidden = false;
    document.getElementById('appShell').hidden = true;
}

function switchAuthMode(mode) {
    const isLogin = mode === 'login';
    document.getElementById('loginTab').classList.toggle('active', isLogin);
    document.getElementById('signupTab').classList.toggle('active', !isLogin);
    document.getElementById('loginTab').setAttribute('aria-selected', String(isLogin));
    document.getElementById('signupTab').setAttribute('aria-selected', String(!isLogin));
    document.getElementById('loginForm').hidden = !isLogin;
    document.getElementById('signupForm').hidden = isLogin;
    document.getElementById('authTitle').textContent = isLogin ? 'Welcome back' : 'Create your account';
    document.getElementById('authSubtitle').textContent = isLogin
        ? 'Sign in to manage your attendance dashboard.'
        : 'Create an account to access your attendance dashboard.';
    setAuthMessage('');
}

async function requestApi(action, options) {
    const response = await fetch(`api.php?action=${action}`, {
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        ...options
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'Request failed.');
    return data;
}

async function initializeAuth() {
    const rememberedLogin = JSON.parse(localStorage.getItem(REMEMBER_KEY) || 'null');

    if (rememberedLogin) {
        document.getElementById('loginEmail').value = rememberedLogin.email || '';
        document.getElementById('loginPassword').value = rememberedLogin.password || '';
        document.getElementById('rememberPassword').checked = true;
    }

    showAuth();
    try {
        const data = await requestApi('me');
        await showApp(data.user);
    } catch (error) {
        // No active session keeps the login form visible.
    }

    document.getElementById('loginTab').addEventListener('click', function () { switchAuthMode('login'); });
    document.getElementById('signupTab').addEventListener('click', function () { switchAuthMode('signup'); });

    document.getElementById('loginForm').addEventListener('submit', async function (event) {
        event.preventDefault();
        const email = document.getElementById('loginEmail').value.trim().toLowerCase();
        const password = document.getElementById('loginPassword').value;
        if (document.getElementById('rememberPassword').checked) {
            localStorage.setItem(REMEMBER_KEY, JSON.stringify({ email: email, password: password }));
        } else {
            localStorage.removeItem(REMEMBER_KEY);
        }

        try {
            const data = await requestApi('login', { method: 'POST', body: JSON.stringify({ email, password }) });
            await showApp(data.user);
        } catch (error) {
            setAuthMessage(error.message, 'error');
        }
    });

    document.getElementById('signupForm').addEventListener('submit', async function (event) {
        event.preventDefault();
        const firstName = document.getElementById('signupFirstName').value.trim();
        const middleName = document.getElementById('signupMiddleName').value.trim();
        const lastName = document.getElementById('signupLastName').value.trim();
        const name = [firstName, middleName, lastName].filter(Boolean).join(' ');
        const email = document.getElementById('signupEmail').value.trim().toLowerCase();
        const password = document.getElementById('signupPassword').value;
        try {
            const data = await requestApi('signup', { method: 'POST', body: JSON.stringify({ name, email, password }) });
            localStorage.setItem(REMEMBER_KEY, JSON.stringify({ email: email, password: password }));
            await showApp(data.user);
        } catch (error) {
            setAuthMessage(error.message, 'error');
        }


    });

    document.getElementById('logoutLink').addEventListener('click', function (event) {
        event.preventDefault();
        requestApi('logout', { method: 'POST' }).finally(function () {
            showAuth();
            switchAuthMode('login');
            document.getElementById('loginForm').reset();
        });
    });
}

initializeAuth().catch(function () {
    setAuthMessage('The SQLite service is unavailable. Start the PHP server and try again.', 'error');
});

function toggleMenu() {
    const menu = document.getElementById('menu');
    const button = document.querySelector('.admin-btn');

    if (menu) {
        menu.classList.toggle('show');
    }

    if (button) {
        const isExpanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', String(!isExpanded));
    }
}

function toggleToolMenu(menuId, button) {
    const menu = document.getElementById(menuId);
    if (!menu) return;
    const isOpen = menu.classList.toggle('show');
    button.setAttribute('aria-expanded', String(isOpen));
}

function closeToolMenus() {
    document.querySelectorAll('.tool-popover.show').forEach(function (menu) { menu.classList.remove('show'); });
    document.querySelectorAll('.toolbar-menu.search-menu[open]').forEach(function (menu) { menu.removeAttribute('open'); });
    document.querySelectorAll('.tool-btn[aria-expanded="true"]').forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
}

window.addEventListener('click', function (event) {
    if (!event.target.closest('.search-menu')) closeToolMenus();
    if (!event.target.closest('.admin')) {
        const menu = document.getElementById('menu');
        const button = document.querySelector('.admin-btn');

        if (menu) {
            menu.classList.remove('show');
        }

        if (button) {
            button.setAttribute('aria-expanded', 'false');
        }
    }
});

document.querySelectorAll('.nav-link').forEach(function (link) {
    link.addEventListener('click', function (event) {
        event.preventDefault();

        document.querySelectorAll('.nav-link').forEach(function (item) {
            item.classList.remove('active');
        });

        this.classList.add('active');

        const target = this.dataset.view;
        document.getElementById('homeView').hidden = target !== 'home';
        document.getElementById('scheduleView').hidden = target !== 'schedule';
        document.getElementById('attendanceView').hidden = target !== 'attendance';
        document.getElementById('reportView').hidden = target !== 'report';
        document.getElementById('overviewView').hidden = target !== 'overview';
        document.getElementById('historyView').hidden = target !== 'history';
        document.getElementById('archiveView').hidden = target !== 'archives';
    });
});

function attendanceDateKey(value) {
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function attendanceDateLabel(dateKey) {
    const parts = dateKey.split('-').map(Number);
    return new Date(parts[0], parts[1] - 1, parts[2]).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
}

const attendanceState = {
    selectedDate: attendanceDateKey(new Date()),
    sortKey: '',
    sortDirection: 1,
    page: 1,
    pageSize: 10
};

function renderAttendanceDateControls() {
    const todayKey = attendanceDateKey(new Date());
    const label = document.getElementById('attendanceDateLabel');
    const nextButton = document.querySelector('.attendance-date-nav .date-nav-button:nth-of-type(2)');
    const summary = document.getElementById('attendanceDateSummary');
    const rows = getAttendanceRows().filter(function (row) { return row.dataset.attendanceDate === attendanceState.selectedDate; });
    const counts = { Present: 0, Late: 0, Absent: 0 };
    rows.forEach(function (row) {
        const status = row.cells[6].textContent.trim();
        counts[status] = (counts[status] || 0) + 1;
    });
    if (label) label.textContent = attendanceState.selectedDate === todayKey ? 'Today' : attendanceDateLabel(attendanceState.selectedDate);
    if (nextButton) nextButton.disabled = attendanceState.selectedDate >= todayKey;
    if (summary) summary.textContent = `${counts.Present} Present    ${counts.Late} Late    ${counts.Absent} Absent`;
}

function selectAttendanceDate(dateKey) {
    const todayKey = attendanceDateKey(new Date());
    attendanceState.selectedDate = dateKey > todayKey ? todayKey : dateKey;
    renderAttendanceDateControls();
    applyAttendanceFilters();
}

function changeAttendanceDate(change) {
    const parts = attendanceState.selectedDate.split('-').map(Number);
    const date = new Date(parts[0], parts[1] - 1, parts[2]);
    date.setDate(date.getDate() + change);
    selectAttendanceDate(attendanceDateKey(date));
}

function goToAttendanceToday() {
    selectAttendanceDate(attendanceDateKey(new Date()));
}

function renderAttendanceHistory() {
    const list = document.getElementById('attendanceHistoryList');
    const empty = document.getElementById('attendanceHistoryEmpty');
    if (!list) return;
    const todayKey = attendanceDateKey(new Date());
    const grouped = {};
    dashboardData.attendance.forEach(function (record) {
        const dateKey = attendanceDateKey(record.attendance_date);
        if (!dateKey || dateKey >= todayKey) return;
        if (!grouped[dateKey]) grouped[dateKey] = { total: 0, Present: 0, Late: 0, Absent: 0 };
        grouped[dateKey].total += 1;
        grouped[dateKey][record.status] = (grouped[dateKey][record.status] || 0) + 1;
    });
    const dates = Object.keys(grouped).sort().reverse();
    list.innerHTML = dates.map(function (dateKey) {
        const summary = grouped[dateKey];
        return `<div class="attendance-history-row"><div><strong>${attendanceDateLabel(dateKey)}</strong><span>${summary.total} records • ${summary.Present} present • ${summary.Absent} absent</span></div><button class="menu-item" type="button" onclick="selectAttendanceDate('${dateKey}'); document.querySelector('[data-view=attendance]').click()">View</button></div>`;
    }).join('');
    if (empty) empty.hidden = dates.length > 0;
}

function getAttendanceRows() {
    const tableBody = document.getElementById('attendanceTableBody');
    return tableBody ? Array.from(tableBody.querySelectorAll('tr')) : [];
}

function getAttendanceValue(row, key) {
    const cellMap = { id: 0, name: 1, date: 2, subject: 3, timeIn: 4, timeOut: 5, status: 6 };
    return (row.cells[cellMap[key]]?.textContent || '').trim().toLowerCase();
}

function applyAttendanceFilters() {
    const search = document.getElementById('attendanceSearch').value.trim().toLowerCase();
    const subject = document.getElementById('attendanceSubjectFilter').value;
    const status = document.getElementById('attendanceStatusFilter').value;
    const course = document.getElementById('attendanceCourseFilter').value;

    getAttendanceRows().forEach(function (row) {
        const matches = (!search || getAttendanceValue(row, 'name').includes(search))
            && row.dataset.attendanceDate === attendanceState.selectedDate
            && (!subject || row.cells[3].textContent.trim() === subject)
            && (!status || row.cells[6].textContent.trim() === status)
            && (!course || row.dataset.course === course);
        row.dataset.matchesFilter = String(matches);
    });

    attendanceState.page = 1;
    renderAttendancePage();
}

function resetAttendanceFilters() {
    document.getElementById('attendanceSearch').value = '';
    document.getElementById('attendanceSubjectFilter').value = '';
    document.getElementById('attendanceStatusFilter').value = '';
    document.getElementById('attendanceCourseFilter').value = '';
    getAttendanceRows().forEach(function (row) {
        row.dataset.attendanceDate = row.dataset.attendanceDate || attendanceDateKey(row.cells[2].textContent.trim());
        row.dataset.matchesFilter = 'true';
    });
    attendanceState.sortKey = '';
    attendanceState.sortDirection = 1;
    attendanceState.page = 1;
    renderAttendanceDateControls();
    renderAttendanceDateControls();
    applyAttendanceFilters();
}

function sortAttendance(sortKey) {
    if (attendanceState.sortKey === sortKey) {
        attendanceState.sortDirection *= -1;
    } else {
        attendanceState.sortKey = sortKey;
        attendanceState.sortDirection = 1;
    }

    const tableBody = document.getElementById('attendanceTableBody');
    const rows = getAttendanceRows().sort(function (firstRow, secondRow) {
        return getAttendanceValue(firstRow, sortKey).localeCompare(getAttendanceValue(secondRow, sortKey), undefined, { numeric: true }) * attendanceState.sortDirection;
    });
    rows.forEach(function (row) { tableBody.appendChild(row); });
    renderAttendancePage();
}

function sortAttendanceFromMenu() {
    const select = document.getElementById('attendanceSortSelect');
    if (select) sortAttendance(select.value);
}

function renderAttendancePage() {
    const rows = getAttendanceRows();
    const filteredRows = rows.filter(function (row) { return row.dataset.matchesFilter !== 'false'; });
    const totalPages = Math.max(1, Math.ceil(filteredRows.length / attendanceState.pageSize));
    attendanceState.page = Math.min(attendanceState.page, totalPages);
    const firstIndex = (attendanceState.page - 1) * attendanceState.pageSize;

    rows.forEach(function (row) { row.hidden = true; });
    filteredRows.slice(firstIndex, firstIndex + attendanceState.pageSize).forEach(function (row) { row.hidden = false; });

    const pagination = document.getElementById('attendancePagination');
    if (!pagination) {
        return;
    }
    pagination.innerHTML = `
        <span>Showing ${filteredRows.length ? firstIndex + 1 : 0}-${Math.min(firstIndex + attendanceState.pageSize, filteredRows.length)} of ${filteredRows.length} records</span>
        <button type="button" ${attendanceState.page === 1 ? 'disabled' : ''} onclick="changeAttendancePage(-1)">Previous</button>
        <strong>Page ${attendanceState.page} of ${totalPages}</strong>
        <button type="button" ${attendanceState.page === totalPages ? 'disabled' : ''} onclick="changeAttendancePage(1)">Next</button>
    `;
}

function changeAttendancePage(change) {
    attendanceState.page += change;
    renderAttendancePage();
}

function initializeAttendanceTools() {
    if (!document.getElementById('attendanceTable')) {
        return;
    }
    getAttendanceRows().forEach(function (row) { row.dataset.matchesFilter = 'true'; });
    const search = document.getElementById('attendanceSearch');
    if (search) {
        search.addEventListener('input', applyAttendanceFilters);
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                applyAttendanceFilters();
            }
        });
    }
    document.querySelectorAll('.sort-button').forEach(function (button) {
        button.addEventListener('click', function () { sortAttendance(button.dataset.sortKey); });
    });
    renderAttendancePage();
}

initializeAttendanceTools();

const studentState = { sortKey: '', sortDirection: 1 };

function getStudentRows() {
    const table = document.getElementById('studentTable');
    return table ? Array.from(table.querySelectorAll('tbody tr')) : [];
}

function filterStudents() {
    const search = document.getElementById('studentSearch').value.trim().toLowerCase();
    getStudentRows().forEach(function (row) {
        const rowText = row.textContent.toLowerCase();
        row.hidden = row.dataset.studentStatus === 'archived' || (search && !rowText.includes(search));
    });
}

function sortStudents(sortKey) {
    if (studentState.sortKey === sortKey) {
        studentState.sortDirection *= -1;
    } else {
        studentState.sortKey = sortKey;
        studentState.sortDirection = 1;
    }
    const columnMap = { id: 0, name: 1, course: 2, year: 3, schoolYear: 4, status: 5 };
    const body = document.querySelector('#studentTable tbody');
    getStudentRows().sort(function (firstRow, secondRow) {
        const first = firstRow.cells[columnMap[sortKey]].textContent.trim();
        const second = secondRow.cells[columnMap[sortKey]].textContent.trim();
        if (sortKey === 'name') {
            const surnameOrder = getSurname(first).localeCompare(getSurname(second), undefined, { sensitivity: 'base' });
            if (surnameOrder) return surnameOrder * studentState.sortDirection;
        }
        return first.localeCompare(second, undefined, { numeric: true }) * studentState.sortDirection;
    }).forEach(function (row) { body.appendChild(row); });
    filterStudents();
}

function openStudentProfile(button) {
    const row = button.closest('tr');
    const actionMenu = button.closest('details');
    if (actionMenu) actionMenu.removeAttribute('open');
    document.querySelectorAll('.row-menu[open], .view-toolbar details[open]').forEach(function (menu) {
        menu.removeAttribute('open');
    });
    const cells = row.cells;
    document.getElementById('profileDetails').innerHTML = `
        <dl class="profile-list">
            <dt>Student ID</dt><dd>${cells[0].textContent}</dd>
            <dt>Name</dt><dd>${cells[1].textContent}</dd>
            <dt>Course</dt><dd>${cells[2].textContent}</dd>
            <dt>Year</dt><dd>${cells[3].textContent}</dd>
            <dt>School Year</dt><dd>${cells[4].textContent}</dd>
            <dt>Status</dt><dd>${cells[5].textContent}</dd>
            <dt>Record</dt><dd>${row.dataset.studentStatus === 'archived' ? 'Archived' : 'Active'}</dd>
        </dl>
        <p class="profile-note">Attendance history and guardian details can be connected when the data source is added.</p>
    `;
    const profileModal = document.getElementById('profileModal');
    profileModal.hidden = false;
    profileModal.setAttribute('aria-hidden', 'false');
    profileModal.classList.add('is-open');
    profileModal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeProfileModal() {
    const profileModal = document.getElementById('profileModal');
    profileModal.classList.remove('is-open');
    profileModal.style.display = 'none';
    profileModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

async function toggleStudentArchive(button) {
    const row = button.closest('tr');
    const archived = row.dataset.studentStatus === 'archived';
    try {
        await requestApi('archive-student', { method: 'POST', body: JSON.stringify({ studentId: row.cells[0].textContent.trim(), archived: archived ? 0 : 1 }) });
        await loadDashboardData();
    } catch (error) {
        alert(error.message);
    }
}

function openArchiveSchoolYearModal() {
    const modal = document.getElementById('archiveSchoolYearModal');
    if (!modal) return;
    const startYear = document.getElementById('archiveStartYear');
    const endYear = document.getElementById('archiveEndYear');
    if (startYear && endYear && !endYear.value) endYear.value = String(Number(startYear.value) + 1);
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function initializeArchiveYearOptions() {
    const startYearSelect = document.getElementById('archiveStartYear');
    const endYearSelect = document.getElementById('archiveEndYear');
    if (!startYearSelect || !endYearSelect) return;

    const currentYear = new Date().getFullYear();
    startYearSelect.innerHTML = Array.from({ length: 13 }, function (_, index) {
        const year = currentYear - 2 + index;
        return `<option value="${year}">${year}</option>`;
    }).join('');
    endYearSelect.innerHTML = Array.from({ length: 13 }, function (_, index) {
        return `<option value="${currentYear - 1 + index}">${currentYear - 1 + index}</option>`;
    }).join('');
    startYearSelect.value = String(currentYear);
    endYearSelect.value = String(currentYear + 1);
}

initializeArchiveYearOptions();

const archiveStartYearSelect = document.getElementById('archiveStartYear');
if (archiveStartYearSelect) {
    archiveStartYearSelect.addEventListener('change', function () {
        const endYear = document.getElementById('archiveEndYear');
        if (endYear) endYear.value = String(Number(archiveStartYearSelect.value) + 1);
    });
}

function closeArchiveSchoolYearModal() {
    const modal = document.getElementById('archiveSchoolYearModal');
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

async function confirmArchiveSchoolYear() {
    const startYear = document.getElementById('archiveStartYear').value;
    const endYear = document.getElementById('archiveEndYear').value.trim();
    if (!/^\d{4}$/.test(endYear) || Number(endYear) !== Number(startYear) + 1) {
        alert('Enter the following school year in the To box, such as 2027 for 2026 - 2027.');
        return;
    }
    const schoolYear = `${startYear}-${endYear}`;
    try {
        await requestApi('archive-year', { method: 'POST', body: JSON.stringify({ schoolYear: schoolYear }) });
        closeArchiveSchoolYearModal();
        await loadDashboardData();
    } catch (error) {
        alert(error.message);
    }
}

function initializeStudentTools() {
    const search = document.getElementById('studentSearch');
    if (!search) {
        return;
    }
    search.addEventListener('input', filterStudents);
    filterStudents();
}

initializeStudentTools();

function renderArchivedStudents() {
    const body = document.getElementById('archiveTableBody');
    if (!body) return;
    const archivedStudents = (dashboardData.students || []).filter(function (student) { return Number(student.is_archived) === 1; });
    const groupedYears = {};
    archivedStudents.forEach(function (student) {
        const schoolYear = student.archived_school_year || 'Unassigned';
        if (!groupedYears[schoolYear]) groupedYears[schoolYear] = [];
        groupedYears[schoolYear].push(student);
    });
    const schoolYearFilter = document.getElementById('archiveSchoolYearFilter');
    if (schoolYearFilter) {
        const selectedYear = schoolYearFilter.value;
        const schoolYears = Object.keys(groupedYears).sort();
        schoolYearFilter.innerHTML = '<option value="">All school years</option>' + schoolYears.map(function (schoolYear) {
            return `<option value="${escapeHtml(schoolYear)}">${escapeHtml(schoolYear)}</option>`;
        }).join('');
        schoolYearFilter.value = schoolYears.includes(selectedYear) ? selectedYear : '';
    }
    body.innerHTML = Object.keys(groupedYears).sort().map(function (year) {
        const students = groupedYears[year];
        const courses = Array.from(new Set(students.map(function (student) { return student.course; }))).sort().join(', ');
        const names = students.map(function (student) { return student.full_name; }).join(' ');
        return `<tr data-archive-year="${escapeHtml(year)}" data-archive-search="${escapeHtml(year + ' ' + courses + ' ' + names)}">
            <td>${escapeHtml(year)}</td><td>${students.length} students</td><td>${escapeHtml(courses)}</td>
            <td><details class="row-menu"><summary aria-label="Archived school year actions">•••</summary><div class="row-menu__content"><button class="menu-item archive-action restore-action" type="button" onclick="restoreArchivedYear(this)">Restore school year</button></div></details></td>
        </tr>`;
    }).join('');
    filterArchivedStudents();
}

async function restoreArchivedYear(button) {
    const schoolYear = button.closest('tr').dataset.archiveYear;
    try {
        await requestApi('restore-year', { method: 'POST', body: JSON.stringify({ schoolYear: schoolYear }) });
        await loadDashboardData();
    } catch (error) {
        alert(error.message);
    }
}

function filterArchivedStudents() {
    const search = (document.getElementById('archiveSearch')?.value || '').trim().toLowerCase();
    const course = document.getElementById('archiveCourseFilter')?.value || '';
    const year = document.getElementById('archiveSchoolYearFilter')?.value || '';
    const rows = Array.from(document.querySelectorAll('#archiveTableBody tr'));
    rows.forEach(function (row) {
        const matchesSearch = !search || row.dataset.archiveSearch.toLowerCase().includes(search);
        const matchesCourse = !course || row.cells[2].textContent.split(', ').includes(course);
        const matchesYear = !year || row.dataset.archiveYear === year;
        row.hidden = !(matchesSearch && matchesCourse && matchesYear);
    });
    const emptyState = document.getElementById('archiveEmptyState');
    if (emptyState) emptyState.hidden = rows.some(function (row) { return !row.hidden; });
}

['archiveSearch', 'archiveCourseFilter', 'archiveSchoolYearFilter'].forEach(function (id) {
    const control = document.getElementById(id);
    if (control) control.addEventListener(control.tagName === 'SELECT' ? 'change' : 'input', filterArchivedStudents);
});

function searchHistory() {
    const input = document.getElementById('historySearchInput');
    const query = input ? input.value.trim().toLowerCase() : '';
    document.querySelectorAll('#timeline .history-card').forEach(function (card) {
        card.hidden = Boolean(query) && !card.textContent.toLowerCase().includes(query);
    });
    document.querySelectorAll('#attendanceHistoryList .attendance-history-row').forEach(function (row) {
        row.hidden = Boolean(query) && !row.textContent.toLowerCase().includes(query);
    });
}

function initializeExpandableSearches() {
    document.querySelectorAll('.toolbar-search input[type="search"]').forEach(function (input) {
        input.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            if (input.id === 'studentSearch') filterStudents();
            if (input.id === 'historySearchInput') searchHistory();
        });
    });
}

initializeExpandableSearches();

function initializeExclusiveToolbarMenus() {
    document.querySelectorAll('.view-toolbar details').forEach(function (menu) {
        menu.addEventListener('toggle', function () {
            if (!menu.open) return;
            const toolbar = menu.closest('.view-toolbar');
            if (!toolbar) return;
            toolbar.querySelectorAll('details[open]').forEach(function (otherMenu) {
                if (otherMenu !== menu) otherMenu.removeAttribute('open');
            });
        });
    });
}

initializeExclusiveToolbarMenus();

function sortStudentsFromMenu() {
    const select = document.getElementById('studentSortSelect');
    if (select) sortStudents(select.value);
}

function setStudentStatus(value) {
    const status = document.getElementById('studentStatusFilter');
    if (status) {
        status.value = value;
        filterStudents();
    }
}

function refreshDashboard() {
    loadDashboardData().catch(function (error) { alert(error.message); });
}

function applyRolePermissions(role) {
    const adminOnly = document.querySelectorAll('[data-admin-only="true"], .archive-action, .edit-btn, .delete-btn');
    adminOnly.forEach(function (element) {
        element.hidden = role !== 'Administrator';
    });
}

function renderAuditLog() {
    const log = document.getElementById('auditLog');
    if (!log) return;
    const entries = dashboardData.audit || [];
    log.innerHTML = entries.length ? entries.map(function (entry) {
        return `<p><strong>${entry.created_at}</strong> ${escapeHtml(entry.actor)}: ${escapeHtml(entry.action)}</p>`;
    }).join('') : '<p>No audit activity yet.</p>';
}

async function saveNotificationSettings() {
    const settings = {
        absent: document.getElementById('notifyAbsent').checked,
        late: document.getElementById('notifyLate').checked,
        checkIn: document.getElementById('notifyCheckIn').checked
    };
    try {
        await requestApi('settings', { method: 'POST', body: JSON.stringify(settings) });
        await loadDashboardData();
    } catch (error) {
        alert(error.message);
    }
}

function loadNotificationSettings() {
    const settings = dashboardData.notificationSettings;
    if (!settings) return;
    if (document.getElementById('notifyAbsent')) document.getElementById('notifyAbsent').checked = settings.absent !== false;
    if (document.getElementById('notifyLate')) document.getElementById('notifyLate').checked = settings.late !== false;
    if (document.getElementById('notifyCheckIn')) document.getElementById('notifyCheckIn').checked = settings.checkIn !== false;
}

function loadNotificationSettingsFromData() { loadNotificationSettings(); }

function updateOverviewStats() {
    const rows = Array.from(document.querySelectorAll('#attendanceTableBody tr'));
    const present = rows.filter(function (row) { return row.cells[6].textContent.trim() === 'Present'; }).length;
    const late = rows.filter(function (row) { return row.cells[6].textContent.trim() === 'Late'; }).length;
    const absent = rows.filter(function (row) { return row.cells[6].textContent.trim() === 'Absent'; }).length;
    const total = present + late + absent;
    const cards = document.querySelectorAll('#overviewView .summary-card p');
    if (cards.length >= 4) {
        cards[0].textContent = document.querySelectorAll('#studentTable tbody tr').length;
        cards[1].textContent = present;
        cards[2].textContent = absent;
        cards[3].textContent = total ? `${Math.round(((present + late) / total) * 100)}%` : '0%';
    }
}

function initializeGlobalSearch() {
    const input = document.getElementById('globalSearch');
    const results = document.getElementById('globalSearchResults');
    if (!input || !results) return;
    input.addEventListener('input', function () {
        const query = input.value.trim().toLowerCase();
        if (!query) {
            results.innerHTML = '';
            return;
        }
        const matches = Array.from(document.querySelectorAll('.view-section tbody tr, .history-card')).filter(function (item) {
            return item.textContent.toLowerCase().includes(query);
        }).slice(0, 8);
        results.innerHTML = matches.length ? matches.map(function (item) {
            return `<button type="button">${item.textContent.trim().slice(0, 70)}</button>`;
        }).join('') : '<span>No matches</span>';
    });
}

renderAuditLog();
initializeGlobalSearch();
updateOverviewStats();

['scheduleDayFilter', 'scheduleInstructorFilter', 'scheduleSubjectFilter', 'scheduleRoomFilter'].forEach(function (id) {
    const control = document.getElementById(id);
    if (control) {
        control.addEventListener(control.tagName === 'SELECT' ? 'change' : 'input', applyScheduleFilters);
    }
});

function applyScheduleFilters() {
    const day = document.getElementById('scheduleDayFilter').value.toLowerCase();
    const instructor = document.getElementById('scheduleInstructorFilter').value.trim().toLowerCase();
    const subject = document.getElementById('scheduleSubjectFilter').value.trim().toLowerCase();
    const room = document.getElementById('scheduleRoomFilter').value.trim().toLowerCase();
    document.querySelectorAll('#scheduleTableBody tr').forEach(function (row) {
        row.hidden = (day && !row.cells[3].textContent.toLowerCase().includes(day))
            || (instructor && !row.cells[1].textContent.toLowerCase().includes(instructor))
            || (subject && !row.cells[0].textContent.toLowerCase().includes(subject))
            || (room && !row.cells[2].textContent.toLowerCase().includes(room));
    });
}

function resetScheduleFilters() {
    document.getElementById('scheduleDayFilter').value = '';
    document.getElementById('scheduleInstructorFilter').value = '';
    document.getElementById('scheduleSubjectFilter').value = '';
    document.getElementById('scheduleRoomFilter').value = '';
    applyScheduleFilters();
}

function timeToMinutes(value) {
    const match = value.trim().match(/(\d+):(\d+)\s*(AM|PM)?/i);
    if (!match) {
        return null;
    }
    let hours = Number(match[1]);
    const minutes = Number(match[2]);
    const meridiem = (match[3] || '').toUpperCase();
    if (meridiem === 'PM' && hours !== 12) hours += 12;
    if (meridiem === 'AM' && hours === 12) hours = 0;
    return hours * 60 + minutes;
}

function hasScheduleConflict(day, room, startTime, endTime, ignoredRow) {
    const start = timeToMinutes(startTime);
    const end = timeToMinutes(endTime);
    return Array.from(document.querySelectorAll('#scheduleTableBody tr')).some(function (row) {
        if (row === ignoredRow || row.hidden || row.cells[3].textContent.trim() !== day || row.cells[2].textContent.trim().toLowerCase() !== room.toLowerCase()) {
            return false;
        }
        const existingTimes = row.cells[4].textContent.split(' - ');
        const existingStart = timeToMinutes(existingTimes[0]);
        const existingEnd = timeToMinutes(existingTimes[1]);
        return start !== null && end !== null && existingStart !== null && existingEnd !== null && start < existingEnd && end > existingStart;
    });
}

function generateReport() {
    const from = document.getElementById('reportFromDate').value;
    const to = document.getElementById('reportToDate').value;
    const course = document.getElementById('reportCourseFilter').value;
    const message = document.getElementById('reportRangeMessage');
    if (message) {
        message.textContent = `Report generated${from ? ` from ${from}` : ''}${to ? ` to ${to}` : ''}${course ? ` for ${course}` : ''}.`;
    }
}

function exportReport() {
    const rows = Array.from(document.querySelectorAll('#reportTable tr')).map(function (row) {
        return Array.from(row.cells).map(function (cell) { return `"${cell.textContent.replace(/"/g, '""')}"`; }).join(',');
    });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([rows.join('\n')], { type: 'text/csv' }));
    link.download = 'attendance-report.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

function closeModal() {
    const modal = document.getElementById('modal');
    if (modal) {
        modal.style.display = 'none';
    }
    clearForm();
}

function closeStudentModal() {
    const modal = document.getElementById('studentModal');
    if (modal) {
        modal.style.display = 'none';
    }
    clearStudentForm();
}

function openStudentModal() {
    const modal = document.getElementById('studentModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function clearForm() {
    document.getElementById('subject').value = '';
    document.getElementById('teacher').value = '';
    document.getElementById('room').value = '';
    document.getElementById('day').value = '';
    document.getElementById('startTime').value = '';
    document.getElementById('endTime').value = '';
}

function clearStudentForm() {
    document.getElementById('studentId').value = '';
    document.getElementById('studentFirstName').value = '';
    document.getElementById('studentMiddleName').value = '';
    document.getElementById('studentLastName').value = '';
    document.getElementById('course').value = '';
    document.getElementById('studentSchoolYearStart').value = '';
    document.getElementById('studentSchoolYearEnd').value = '';
    document.getElementById('year').value = '';
    document.getElementById('status').value = '';
    document.getElementById('facePhoto').value = '';
}

function readFacePhoto(file) {
    return new Promise(function (resolve, reject) {
        const reader = new FileReader();
        reader.addEventListener('load', function () { resolve(reader.result); });
        reader.addEventListener('error', function () { reject(new Error('Unable to read the face photo.')); });
        reader.readAsDataURL(file);
    });
}

function formatParentPhone(value) {
    const digits = value.replace(/\D/g, '');
    let localNumber = digits;
    if (digits.startsWith('63')) {
        localNumber = digits.slice(2);
    } else if (digits.startsWith('0')) {
        localNumber = digits.slice(1);
    }
    if (!localNumber.startsWith('9')) return value.replace(/[^0-9+\s().-]/g, '');
    localNumber = localNumber.slice(0, 10);
    const groups = [localNumber.slice(0, 3), localNumber.slice(3, 6), localNumber.slice(6, 10)].filter(Boolean);
    return `+63 ${groups.join(' ')}`;
}

function initializeParentPhoneFormatter() {
    const input = document.getElementById('parentPhone');
    if (!input) return;
    input.addEventListener('input', function () {
        input.value = formatParentPhone(input.value);
    });
}

initializeParentPhoneFormatter();

async function addStudent() {
    const studentId = document.getElementById('studentId').value.trim();
    const firstName = document.getElementById('studentFirstName').value.trim();
    const middleName = document.getElementById('studentMiddleName').value.trim();
    const lastName = document.getElementById('studentLastName').value.trim();
    const fullName = [firstName, middleName, lastName].filter(Boolean).join(' ');
    const course = document.getElementById('course').value.trim();
    const schoolYearStart = document.getElementById('studentSchoolYearStart').value.trim();
    const schoolYearEnd = document.getElementById('studentSchoolYearEnd').value.trim();
    const schoolYear = `${schoolYearStart}-${schoolYearEnd}`;
    const year = document.getElementById('year').value.trim();
    const status = document.getElementById('status').value.trim();
    const facePhoto = document.getElementById('facePhoto').files[0];

    if (!studentId || !firstName || !lastName || !course || !schoolYearStart || !schoolYearEnd || !year || !status || !facePhoto) {
        alert('Please complete all required fields.');
        return;
    }
    if (!/^\d{4}$/.test(schoolYearStart) || !/^\d{4}$/.test(schoolYearEnd) || Number(schoolYearEnd) !== Number(schoolYearStart) + 1) {
        alert('Enter consecutive school years, such as 2026 and 2027.');
        return;
    }
    if (facePhoto.size > 5 * 1024 * 1024) {
        alert('The face photo must be 5 MB or smaller.');
        return;
    }

    try {
        const facePhotoData = await readFacePhoto(facePhoto);
        await requestApi('student', { method: 'POST', body: JSON.stringify({ studentId, fullName, course, schoolYear, year, status, facePhoto: facePhotoData }) });
        closeStudentModal();
        clearStudentForm();
        await loadDashboardData();
    } catch (error) {
        alert(error.message);
    }
}

async function addSchedule() {
    const subject = document.getElementById('subject').value.trim();
    const teacher = document.getElementById('teacher').value.trim();
    const room = document.getElementById('room').value.trim();
    const day = document.getElementById('day').value.trim();
    const startTime = document.getElementById('startTime').value.trim();
    const endTime = document.getElementById('endTime').value.trim();

    if (!subject || !teacher || !room || !day || !startTime || !endTime) {
        alert('Please complete all fields.');
        return;
    }

    if (hasScheduleConflict(day, room, startTime, endTime, editingRow)) {
        alert(`Schedule conflict: ${room} is already occupied on ${day} during this time.`);
        return;
    }

    try {
        await requestApi('schedule', { method: 'POST', body: JSON.stringify({ id: editingRow?.dataset.scheduleId, subject, teacher, room, day, startTime, endTime }) });
        closeModal();
        clearForm();
        editingRow = null;
        await loadDashboardData();
    } catch (error) {
        alert(error.message);
    }
}

function editRow(button) {
    const row = button.closest('tr');
    editingRow = row;

    document.getElementById('modalTitle').textContent = 'Edit Schedule';
    document.getElementById('saveScheduleBtn').textContent = 'Update Schedule';

    document.getElementById('subject').value = row.cells[0].textContent;
    document.getElementById('teacher').value = row.cells[1].textContent;
    document.getElementById('room').value = row.cells[2].textContent;
    document.getElementById('day').value = row.cells[3].textContent;

    const timeText = row.cells[4].textContent;
    const [startTime, endTime] = timeText.split(' - ');
    document.getElementById('startTime').value = startTime || '';
    document.getElementById('endTime').value = endTime || '';

    const modal = document.getElementById('modal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function openDeleteModal(button) {
    rowToDelete = button.closest('tr');
    const modal = document.getElementById('deleteConfirmModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeDeleteModal() {
    rowToDelete = null;
    const modal = document.getElementById('deleteConfirmModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function confirmDelete() {
    if (rowToDelete) {
        const scheduleId = rowToDelete.dataset.scheduleId;
        if (scheduleId) {
            requestApi('delete-schedule', { method: 'POST', body: JSON.stringify({ id: scheduleId }) })
                .then(loadDashboardData)
                .catch(function (error) { alert(error.message); });
        }
    }
    closeDeleteModal();
}

function searchStudent() {
    const input = document.getElementById('searchInput').value.trim().toUpperCase();
    const table = document.getElementById('attendanceTable');
    if (!table) {
        return;
    }

    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(function (row) {
        const nameCell = row.cells[1];
        const nameText = nameCell ? nameCell.textContent.trim().toUpperCase() : '';
        row.style.display = nameText.indexOf(input) > -1 ? '' : 'none';
    });
}

function searchReport() {
    const input = document.getElementById('reportSearchInput').value.trim().toUpperCase();
    const table = document.getElementById('reportTable');
    if (!table) {
        return;
    }

    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(function (row) {
        const nameCell = row.cells[1];
        const nameText = nameCell ? nameCell.textContent.trim().toUpperCase() : '';
        row.style.display = nameText.indexOf(input) > -1 ? '' : 'none';
    });
}

function openTimePicker() {
    const picker = document.getElementById('timePicker');
    if (!picker) {
        return;
    }

    try {
        if (typeof picker.showPicker === 'function') {
            picker.showPicker();
            return;
        }
    } catch (error) {
        console.warn('Picker could not be opened:', error);
    }

    picker.focus();
    picker.click();
}

function setTimeValue(value) {
    const input = document.getElementById('time');
    if (input) {
        input.value = value;
    }
}
