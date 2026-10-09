import { createInertiaApp } from '@inertiajs/react';
import { initializeTheme } from '@/hooks/use-appearance';
import AuthLayout from '@/layouts/auth-layout';

const appName = import.meta.env.VITE_APP_NAME || 'LearnTrack';

void createInertiaApp({
    title: (title: string) => (title ? `${title} - ${appName}` : appName),
    layout: (name: string) => (name.startsWith('auth/') ? AuthLayout : null),
    strictMode: true,
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
