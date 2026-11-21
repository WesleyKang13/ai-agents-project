import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { SharedProps } from '../types';

export default function AppLayout({ children }: { children: ReactNode }) {
    const { props, component } = usePage<SharedProps>();

    return (
        <div className="flex min-h-screen flex-col">
            <a href="#main" className="sr-only focus:not-sr-only focus:p-4">
                Skip to content
            </a>
            <header className="border-b border-line">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-5 px-6 py-6 sm:px-10">
                    <Link
                        href={props.navigation.home}
                        className="flex items-center gap-3 font-semibold tracking-tight"
                    >
                        <span
                            aria-hidden="true"
                            className="grid size-9 place-items-center rounded-xl bg-forest text-sm text-cream"
                        >
                            A
                        </span>
                        {props.projectName}
                    </Link>
                    <nav aria-label="Main navigation" className="flex gap-1 text-sm font-medium">
                        {(
                            [
                                ['Home', props.navigation.home],
                                ['Workshop', props.navigation.workshop],
                            ] as const
                        ).map(([label, href]) => (
                            <Link
                                key={label}
                                href={href}
                                aria-current={component === label ? 'page' : undefined}
                                className={`rounded-full px-4 py-2 transition-colors hover:bg-sage ${component === label ? 'bg-sage text-forest' : 'text-muted'}`}
                            >
                                {label}
                            </Link>
                        ))}
                        {props.auth.user && (
                            <button
                                onClick={() => router.post(props.logoutUrl)}
                                className="rounded-full px-4 py-2 text-muted hover:bg-sage"
                            >
                                Sign out
                            </button>
                        )}
                    </nav>
                </div>
            </header>
            <main
                id="main"
                className="mx-auto w-full max-w-6xl flex-1 px-6 py-12 sm:px-10 sm:py-16"
            >
                {children}
            </main>
            <footer className="mx-auto flex w-full max-w-6xl flex-wrap justify-between gap-3 px-6 py-6 text-xs text-muted sm:px-10">
                <span>A little space for big ideas.</span>
                <span>AI Agents Project · Your private workshop</span>
            </footer>
        </div>
    );
}
