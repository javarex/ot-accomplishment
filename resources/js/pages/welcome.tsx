import { Head, Link, usePage } from '@inertiajs/react';
import { FileText, Sparkles, Upload } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login, register } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Accomplishment Report Generator" />
            <div className="min-h-screen bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-5 py-5">
                    <div className="flex items-center gap-3">
                        <div className="flex size-12 items-center justify-center rounded-2xl bg-[#17354d] text-white">
                            <AppLogoIcon className="size-8" />
                        </div>
                        <div>
                            <p className="font-semibold">Accomplishment</p>
                            <p className="text-[10px] font-medium tracking-[0.16em] text-muted-foreground uppercase">
                                Report Generator
                            </p>
                        </div>
                    </div>
                    <nav className="flex gap-2 text-sm">
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="rounded-xl bg-primary px-4 py-2 font-medium text-primary-foreground"
                            >
                                Dashboard
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className="rounded-xl border bg-card px-4 py-2 font-medium"
                                >
                                    Log in
                                </Link>
                                <Link
                                    href={register()}
                                    className="rounded-xl bg-primary px-4 py-2 font-medium text-primary-foreground"
                                >
                                    Register
                                </Link>
                            </>
                        )}
                    </nav>
                </header>
                <main className="mx-auto grid w-full max-w-6xl gap-10 px-5 py-14 md:grid-cols-[1.2fr_1fr] md:items-center md:py-24">
                    <div>
                        <p className="mb-4 w-fit rounded-full border border-primary/20 bg-accent px-3 py-1 text-xs font-semibold tracking-widest text-primary uppercase">
                            Report preparation, simplified
                        </p>
                        <h1 className="max-w-2xl text-4xl font-semibold tracking-tight md:text-6xl">
                            Turn daily work into a report ready for approval.
                        </h1>
                        <p className="mt-5 max-w-xl text-lg text-muted-foreground">
                            Record accomplishments, review dates from a Daily
                            Time Record, and produce a polished report for
                            signing and filing.
                        </p>
                        <div className="mt-8 flex flex-wrap gap-3">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="rounded-xl bg-primary px-5 py-3 text-sm font-medium text-primary-foreground shadow-sm"
                                >
                                    Open dashboard
                                </Link>
                            ) : (
                                <Link
                                    href={register()}
                                    className="rounded-xl bg-primary px-5 py-3 text-sm font-medium text-primary-foreground shadow-sm"
                                >
                                    Get started
                                </Link>
                            )}
                        </div>
                    </div>
                    <div className="grid gap-5 rounded-3xl border bg-card p-7 shadow-xl shadow-[#17354d]/8">
                        <div className="border-b pb-4">
                            <p className="text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                                A clear workflow
                            </p>
                            <h2 className="mt-1 text-xl font-semibold">
                                From notes to finished report
                            </h2>
                        </div>
                        <div className="flex items-start gap-3">
                            <Upload className="mt-1 size-5 text-primary" />
                            <div>
                                <h2 className="font-medium">Import a DTR</h2>
                                <p className="text-sm text-muted-foreground">
                                    Review detected overtime dates and
                                    quantities before adding them to a report.
                                </p>
                            </div>
                        </div>
                        <div className="flex items-start gap-3">
                            <Sparkles className="mt-1 size-5 text-primary" />
                            <div>
                                <h2 className="font-medium">
                                    Improve task wording
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Approve AI suggestions while preserving your
                                    original statement.
                                </p>
                            </div>
                        </div>
                        <div className="flex items-start gap-3">
                            <FileText className="mt-1 size-5 text-primary" />
                            <div>
                                <h2 className="font-medium">
                                    Export the finished report
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Use saved signatories and your office
                                    template to export PDF or DOCX.
                                </p>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </>
    );
}
