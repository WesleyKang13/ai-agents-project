import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';

createInertiaApp({
    title: (title) => `${title} · AI Agents Project`,
    strictMode: true,
    pages: './pages',
});
