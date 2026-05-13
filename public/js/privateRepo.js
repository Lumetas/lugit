const creds = JSON.parse(localStorage.getItem('lugit_credentials'));

if (creds && creds.username && creds.password) {
	const credentials = btoa(`${creds.username}:${creds.password}`);
	fetch(window.location.href, {
		method: 'GET',
		headers: {
			'Authorization': `Basic ${credentials}`,
			'Content-Type': 'application/json'
		}
	})
		.then(response => {
			if (response.status === 401) {
				console.error('Unauthorized: Invalid credentials');
				// Опционально: очистить нерабочие credentials
				localStorage.removeItem('lugit_credentials');
				return null;
			}
			if (!response.ok) {
				console.error(`HTTP error! status: ${response.status}`);
				return null;
			}
			return response.text();
		})
		.then(data => {
			if (data !== null) {
				document.documentElement.innerHTML = data;
			}
		})
		.catch(error => console.error('Error:', error));
}
