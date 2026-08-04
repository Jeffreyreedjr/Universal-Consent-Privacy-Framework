import { createRoot } from 'react-dom/client';
import { Dashboard } from './pages/Dashboard';

const el = document.getElementById( 'ucpf-admin-root' );
if ( el ) {
	const data = window.ucpfDashboard || {};
	createRoot( el ).render( <Dashboard data={ data } /> );
}
