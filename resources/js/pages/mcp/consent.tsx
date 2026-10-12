import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

type ConsentProps = {
    clientName: string;
    userName: string;
    capabilities: string[];
    authToken: string;
    state: string | null;
    csrfToken: string;
};

export default function Consent({
    clientName,
    userName,
    capabilities,
    authToken,
    state,
    csrfToken,
}: ConsentProps) {
    const hiddenFields = (
        <>
            <input type="hidden" name="_token" value={csrfToken} />
            <input type="hidden" name="state" value={state ?? ''} />
            <input type="hidden" name="auth_token" value={authToken} />
        </>
    );

    return (
        <AuthLayout
            title={`Connect ${clientName}`}
            description={`You are signed in as ${userName}.`}
        >
            <Head title="Connect an AI assistant" />

            <div className="flex flex-col gap-6">
                <div className="space-y-2 text-sm">
                    <p>{clientName} will be able to:</p>
                    <ul className="list-disc space-y-1 pl-5">
                        {capabilities.map((capability) => (
                            <li key={capability}>{capability}</li>
                        ))}
                    </ul>
                </div>

                <div className="flex gap-3">
                    <AuthorizationForm fields={hiddenFields}>
                        <Button type="submit" className="w-full">
                            Approve
                        </Button>
                    </AuthorizationForm>

                    <AuthorizationForm fields={hiddenFields} deny>
                        <Button
                            type="submit"
                            variant="outline"
                            className="w-full"
                        >
                            Deny
                        </Button>
                    </AuthorizationForm>
                </div>
            </div>
        </AuthLayout>
    );
}

function AuthorizationForm({
    fields,
    deny = false,
    children,
}: {
    fields: ReactNode;
    deny?: boolean;
    children: ReactNode;
}) {
    return (
        <form method="post" action="/oauth/authorize" className="flex-1">
            {deny && <input type="hidden" name="_method" value="DELETE" />}
            {fields}
            {children}
        </form>
    );
}
