const API_PREFIX = '/api/v1';

function getStoredCredentials() {
	const creds = localStorage.getItem('lugit_credentials');
	return creds ? JSON.parse(creds) : null;
}

function setStoredCredentials(server, username, password) {
	localStorage.setItem('lugit_credentials', JSON.stringify({ server, username, password }));
}

function clearStoredCredentials() {
	localStorage.removeItem('lugit_credentials');
}

function copyRepoUrl(name) {
	copyToClipboard(window.location.origin + '/' + name);
}

async function copyToClipboard(text) {
	// 1. Пытаемся использовать современный API
	if (navigator.clipboard && window.isSecureContext) {
		try {
			await navigator.clipboard.writeText(text);
			showToast('Copied to clipboard');
			return;
		} catch (err) {
			console.error('Clipboard API Error', err);
		}
	}

	// 2. Fallback: создание временного элемента <textarea>
	const textArea = document.createElement("textarea");
	textArea.value = text;

	// Убираем поле из области видимости
	textArea.style.position = "fixed";
	textArea.style.left = "-9999px";
	textArea.style.top = "0";
	document.body.appendChild(textArea);

	textArea.focus();
	textArea.select();

	try {
		const successful = document.execCommand('copy');
		if (successful) {
			showToast('Copied to clipboard');
		} else {
			throw new Error('Команда копирования не удалась');
		}
	} catch (err) {
		console.error('Ошибка в fallback-методе: ', err);
	}

	document.body.removeChild(textArea);
}

function getApiBase() {
	const creds = getStoredCredentials();
	if (creds && creds.server) {
		return creds.server + API_PREFIX;
	}
	return window.location.origin + API_PREFIX;
}

function getAuthHeader() {
	const creds = getStoredCredentials();
	if (creds && creds.username && creds.password) {
		return 'Basic ' + btoa(creds.username + ':' + creds.password);
	}
	return null;
}

async function apiRequest(endpoint, options = {}) {
	const url = getApiBase() + endpoint;
	const headers = {
		'Content-Type': 'application/json',
		...options.headers
	};

	const authHeader = getAuthHeader();
	if (authHeader) {
		headers['Authorization'] = authHeader;
	}

	try {
		const response = await fetch(url, {
			method: options.method || 'GET',
			headers,
			body: options.body ? JSON.stringify(options.body) : undefined
		});

		const data = await response.json();

		if (!response.ok) {
			throw new Error(data.error || 'Request failed');
		}

		return data;
	} catch (error) {
		throw error;
	}
}

function showToast(message, type = 'success') {
	const toast = document.getElementById('toast');
	toast.textContent = message;
	toast.className = 'toast toast-' + type + ' show';
	setTimeout(() => {
		toast.classList.remove('show');
	}, 3000);
}

async function apiLogin() {
	const server = window.location.origin;
	const username = document.getElementById('username').value.trim();
	const password = document.getElementById('password').value;

	if (!username || !password) {
		showToast('Please enter username and password', 'error');
		return;
	}

	setStoredCredentials(server, username, password);

	try {
		const data = await apiRequest('/login', {
			method: 'POST',
			body: { username, password }
		});
		showDashboard();
		showToast('Login successful!');
	} catch (error) {
		clearStoredCredentials();
		showToast(error.message, 'error');
	}
}

function showLogout() {
	showModal('Logout', `
                <p>Are you sure you want to logout?</p>
                <div class="form-actions">
                    <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-danger" onclick="doLogout()">Logout</button>
                </div>
            `);
}

function doLogout() {
	clearStoredCredentials();
	closeModal();
	showLogin();
	showToast('Logged out');
}

function showLogin() {
	document.getElementById('loginSection').style.display = 'block';
	document.getElementById('dashboardSection').style.display = 'none';
}

async function showDashboard() {
	document.getElementById('loginSection').style.display = 'none';
	document.getElementById('dashboardSection').style.display = 'block';

	const creds = getStoredCredentials();
	if (creds) {
		document.getElementById('currentUser').textContent = creds.username;
		// document.getElementById('serverUrl').value = creds.server || '';
		document.getElementById('username').value = creds.username || '';
	}

	await loadRepos();
}

async function checkLogin() {
	const creds = getStoredCredentials();
	if (creds && creds.username && creds.password) {
		try {
			await apiRequest('/user');
			showDashboard();
			return;
		} catch (e) {
			clearStoredCredentials();
		}
	}
	showLogin();
}

async function loadRepos() {
	try {
		const repos = await apiRequest('/repos');
		renderRepoList(repos);
		updateRepoSelects(repos);
	} catch (error) {
		document.getElementById('repoList').innerHTML = `<div class="card" style="color: #f85149;">Error: ${error.message}</div>`;
	}
}

function renderRepoList(repos) {
	const container = document.getElementById('repoList');

	if (repos.length === 0) {
		container.innerHTML = `
                    <div class="empty-state">
                        <p>No repositories yet</p>
                        <button class="btn btn-primary" style="margin-top: 16px;" onclick="showCreateRepo()">Create your first repository</button>
                    </div>
                `;
		return;
	}

	container.innerHTML = repos.map(repo => `
                <div class="repo-item">
                    <div class="repo-header">
                        <a href="/repos/${repo.name}" class="repo-name" target="_blank">${repo.name}</a>
                        <span class="badge ${repo.public ? 'badge-public' : 'badge-private'}">
                            ${repo.public ? 'Public' : 'Private'}
                        </span>
                    </div>
                    <div class="repo-actions">
                        <button class="btn btn-secondary btn-small" onclick="copyRepoUrl('${repo.name}')">Copy clone URL</button>
                        <button class="btn btn-secondary btn-small" onclick="showManageUsers('${repo.name}')">Users</button>
                        <button class="btn btn-secondary btn-small" onclick="showCicdForRepo('${repo.name}')">CI/CD</button>
                        ${repo.public ?
			`<button class="btn btn-secondary btn-small" onclick="setVisibility('${repo.name}', false)">Make Private</button>` :
			`<button class="btn btn-secondary btn-small" onclick="setVisibility('${repo.name}', true)">Make Public</button>`
		}
                        <button class="btn btn-danger btn-small" onclick="confirmDelete('${repo.name}')">Delete</button>
                    </div>
                </div>
            `).join('');
}

function updateRepoSelects(repos) {
	const cicdSelect = document.getElementById('cicdRepoSelect');
	const settingsSelect = document.getElementById('settingsRepoSelect');

	const options = '<option value="">-- Select repository --</option>' +
		repos.map(r => `<option value="${r.name}">${r.name}</option>`).join('');

	cicdSelect.innerHTML = options;
	settingsSelect.innerHTML = options;
}

function viewRepo(name) {
	window.open('/repos/' + name, '_blank');
}

function showCreateRepo() {
	showModal('Create New Repository', `
                <div class="form-group">
                    <label>Repository Name</label>
                    <input type="text" id="newRepoName" placeholder="my-awesome-project">
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="newRepoPublic" style="width: auto; margin-right: 8px;">
                        Public (anyone can clone)
                    </label>
                </div>
                <div class="form-actions">
                    <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" onclick="createRepo()">Create</button>
                </div>
            `);
}

async function createRepo() {
	const name = document.getElementById('newRepoName').value.trim();
	const isPublic = document.getElementById('newRepoPublic').checked;

	if (!name) {
		showToast('Please enter repository name', 'error');
		return;
	}

	try {
		await apiRequest('/repos/' + name, { method: 'POST' });

		if (isPublic) {
			await apiRequest('/repos/' + name + '/public', { method: 'PUT' });
		}

		closeModal();
		await loadRepos();
		showToast('Repository created!');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

function confirmDelete(name) {
	showModal('Delete Repository', `
                <p>Are you sure you want to delete <strong>${name}</strong>?</p>
                <p style="color: #f85149; margin-top: 8px;">This action cannot be undone.</p>
                <div class="form-actions">
                    <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-danger" onclick="deleteRepo('${name}')">Delete</button>
                </div>
            `);
}

async function deleteRepo(name) {
	try {
		await apiRequest('/repos/' + name, { method: 'DELETE' });
		closeModal();
		await loadRepos();
		showToast('Repository deleted');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

async function setVisibility(name, isPublic) {
	try {
		await apiRequest('/repos/' + name + '/' + (isPublic ? 'public' : 'private'), { method: 'PUT' });
		await loadRepos();
		showToast('Visibility updated');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

async function showManageUsers(name) {
	try {
		const users = await apiRequest('/repos/' + name + '/users');
		const repo = await apiRequest('/repos/' + name);

		showModal('Manage Users: ' + name, `
                    <h4 style="color: #8b949e; margin-bottom: 12px;">Users with access:</h4>
                    <div id="modalUsersList">
                        ${users.length === 0 ? '<p style="color: #484f58;">No users added</p>' :
				users.map(u => `
                                <div class="user-item">
                                    <span>${u} ${u === repo.allowedUsers[0] ? '(owner)' : ''}</span>
                                    ${u !== getStoredCredentials()?.username ?
						`<button class="btn btn-danger btn-small" onclick="removeUserFromModal('${name}', '${u}')">Remove</button>` :
						'<span style="color: #484f58;">(you)</span>'
					}
                                </div>
                            `).join('')
			}
                    </div>
                    <div style="margin-top: 16px;">
                        <div class="form-group">
                            <label>Add User</label>
                            <input type="text" id="modalNewUser" placeholder="Username">
                        </div>
                        <button class="btn btn-primary" onclick="addUserToModal('${name}')">Add</button>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-secondary" onclick="closeModal()">Close</button>
                    </div>
                `);
	} catch (error) {
		showToast(error.message, 'error');
	}
}

async function addUserToModal(repoName) {
	const username = document.getElementById('modalNewUser').value.trim();
	if (!username) {
		showToast('Enter username', 'error');
		return;
	}
	try {
		await apiRequest('/repos/' + repoName + '/users/' + username, { method: 'POST' });
		showManageUsers(repoName);
		showToast('User added');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

async function removeUserFromModal(repoName, username) {
	try {
		await apiRequest('/repos/' + repoName + '/users/' + username, { method: 'DELETE' });
		showManageUsers(repoName);
		showToast('User removed');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

function showCicdForRepo(name) {
	showTab('cicd');
	document.getElementById('cicdRepoSelect').value = name;
	loadCicdData();
}

async function loadCicdData() {
	const repoName = document.getElementById('cicdRepoSelect').value;
	const content = document.getElementById('cicdContent');

	if (!repoName) {
		content.style.display = 'none';
		return;
	}

	content.style.display = 'block';

	try {
		const data = await apiRequest('/repos/' + repoName + '/cicd');
		const hooks = data.hooks || [];

		const hooksList = document.getElementById('hooksList');
		const branchSelect = document.getElementById('logsBranchSelect');

		if (hooks.length === 0) {
			hooksList.innerHTML = '<div class="empty-state">No CI/CD hooks configured</div>';
		} else {
			hooksList.innerHTML = hooks.map(branch => `
                        <div class="hook-item">
                            <span>${branch}</span>
                            <div class="hook-actions">
                                <button class="btn btn-primary btn-small" onclick="runHook('${repoName}', '${branch}')">Run</button>
                            </div>
                        </div>
                    `).join('');
		}

		branchSelect.innerHTML = '<option value="">-- Select branch --</option>' +
			hooks.map(b => `<option value="${b}">${b}</option>`).join('');
		document.getElementById('logsViewer').textContent = '';

	} catch (error) {
		showToast(error.message, 'error');
	}
}

function showAddHook() {
	const repoName = document.getElementById('cicdRepoSelect').value;
	if (!repoName) return;

	showModal('Add CI/CD Hook', `
                <div class="form-group">
                    <label>Branch</label>
                    <input type="text" id="hookBranch" placeholder="main, develop, etc.">
                </div>
                <div class="form-group">
                    <label>Script (bash)</label>
                    <textarea id="hookScript" placeholder="#!/bin/bash&#10;echo \"Deploying...\"&#10;./deploy.sh"></textarea>
                </div>
                <div class="form-actions">
                    <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" onclick="saveHook('${repoName}')">Save</button>
                </div>
            `);
}

async function viewHookScript(repoName, branch) {
	showModal('Hook Script: ' + branch, `
                <div class="form-group">
                    <label>Script</label>
                    <textarea id="editHookScript" style="height: 200px;" placeholder="Loading..."></textarea>
                </div>
                <div class="form-actions">
                    <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" onclick="updateHookScript('${repoName}', '${branch}')">Update</button>
                </div>
            `);

	setTimeout(async () => {
		try {
			const repoPath = getApiBase().replace('/api/v1', '') + '/repos/' + repoName;
			document.getElementById('editHookScript').value = '# Note: Script viewing requires direct file access.\n# Use lugit CLI to manage hooks.';
		} catch (e) {
			document.getElementById('editHookScript').value = '# Script not accessible via API';
		}
	}, 100);
}

async function updateHookScript(repoName, branch) {
	await saveHook(repoName, branch);
}

async function saveHook(repoName, existingBranch = null) {
	const branch = existingBranch || document.getElementById('hookBranch').value.trim();
	const script = document.getElementById(existingBranch ? 'editHookScript' : 'hookScript').value;

	if (!branch) {
		showToast('Enter branch name', 'error');
		return;
	}

	try {
		await apiRequest('/repos/' + repoName + '/cicd/' + branch, {
			method: 'POST',
			body: { script: btoa(script) }
		});
		closeModal();
		loadCicdData();
		showToast('Hook saved');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

async function runHook(repoName, branch) {
	try {
		await apiRequest('/repos/' + repoName + '/cicd/' + branch + '/run', { method: 'POST' });
		showToast('Hook triggered');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

function confirmDeleteHook(repoName, branch) {
	showModal('Delete Hook', `
                <p>Delete hook for branch <strong>${branch}</strong>?</p>
                <div class="form-actions">
                    <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-danger" onclick="deleteHook('${repoName}', '${branch}')">Delete</button>
                </div>
            `);
}

async function deleteHook(repoName, branch) {
	try {
		await apiRequest('/repos/' + repoName + '/cicd/' + branch, { method: 'DELETE' });
		closeModal();
		loadCicdData();
		showToast('Hook deleted');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

async function loadLogs() {
	const repoName = document.getElementById('cicdRepoSelect').value;
	const branch = document.getElementById('logsBranchSelect').value;
	const viewer = document.getElementById('logsViewer');

	if (!branch) {
		viewer.textContent = '';
		return;
	}

	try {
		const data = await apiRequest('/repos/' + repoName + '/cicd/logs/' + branch);
		viewer.textContent = data.logs[branch] || '';
	} catch (error) {
		viewer.textContent = 'Error: ' + error.message;
	}
}

async function clearLogs() {
	const repoName = document.getElementById('cicdRepoSelect').value;
	const branch = document.getElementById('logsBranchSelect').value;

	if (!branch) return;

	try {
		await apiRequest('/repos/' + repoName + '/cicd/logs/' + branch, { method: 'DELETE' });
		loadLogs();
		showToast('Logs cleared');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

async function loadRepoSettings() {
	const repoName = document.getElementById('settingsRepoSelect').value;
	const content = document.getElementById('settingsContent');

	if (!repoName) {
		content.style.display = 'none';
		return;
	}

	content.style.display = 'block';

	try {
		const [repo, users] = await Promise.all([
			apiRequest('/repos/' + repoName),
			apiRequest('/repos/' + repoName + '/users')
		]);

		document.getElementById('usersList').innerHTML = users.length === 0 ?
			'<p style="color: #484f58;">No users</p>' :
			users.map(u => `
                        <div class="user-item">
                            <span>${u} ${u === repo.allowedUsers[0] ? '(owner)' : ''}</span>
                            ${u !== getStoredCredentials()?.username ?
					`<button class="btn btn-danger btn-small" onclick="settingsRemoveUser('${repoName}', '${u}')">Remove</button>` :
					'<span style="color: #484f58;">(you)</span>'
				}
                        </div>
                    `).join('');

		const visBtn = document.getElementById('toggleVisibilityBtn');
		visBtn.textContent = repo.public ? 'Make Private' : 'Make Public';
		visBtn.onclick = () => settingsToggleVisibility(repoName, !repo.public);

	} catch (error) {
		showToast(error.message, 'error');
	}
}

async function addUser() {
	const repoName = document.getElementById('settingsRepoSelect').value;
	const username = document.getElementById('newUserName').value.trim();

	if (!username) {
		showToast('Enter username', 'error');
		return;
	}

	try {
		await apiRequest('/repos/' + repoName + '/users/' + username, { method: 'POST' });
		document.getElementById('newUserName').value = '';
		loadRepoSettings();
		showToast('User added');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

async function settingsRemoveUser(repoName, username) {
	try {
		await apiRequest('/repos/' + repoName + '/users/' + username, { method: 'DELETE' });
		loadRepoSettings();
		showToast('User removed');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

async function settingsToggleVisibility(repoName, isPublic) {
	try {
		await apiRequest('/repos/' + repoName + '/' + (isPublic ? 'public' : 'private'), { method: 'PUT' });
		loadRepoSettings();
		await loadRepos();
		showToast('Visibility updated');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

function confirmDeleteRepo() {
	const repoName = document.getElementById('settingsRepoSelect').value;
	if (!repoName) return;

	showModal('Delete Repository', `
                <p>Delete <strong>${repoName}</strong>?</p>
                <p style="color: #f85149;">This cannot be undone.</p>
                <div class="form-actions">
                    <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-danger" onclick="settingsDeleteRepo('${repoName}')">Delete</button>
                </div>
            `);
}

async function settingsDeleteRepo(repoName) {
	try {
		await apiRequest('/repos/' + repoName, { method: 'DELETE' });
		closeModal();
		document.getElementById('settingsRepoSelect').value = '';
		document.getElementById('settingsContent').style.display = 'none';
		await loadRepos();
		showToast('Repository deleted');
	} catch (error) {
		showToast(error.message, 'error');
	}
}

function showTab(tabName) {
	document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
	document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));

	event.target.classList.add('active');
	document.getElementById(tabName + 'Section').classList.add('active');
}

function showModal(title, content) {
	document.getElementById('modalContent').innerHTML = `<h3>${title}</h3>` + content;
	document.getElementById('modalOverlay').classList.add('active');
}

function closeModal() {
	document.getElementById('modalOverlay').classList.remove('active');
}

document.addEventListener('DOMContentLoaded', () => {
	checkLogin();

	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') closeModal();
	});
});

