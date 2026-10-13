import { Head } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

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
    capabilities,
    authToken,
    state,
    csrfToken,
}: ConsentProps) {
    const [answer, setAnswer] = useState<'approve' | 'deny' | null>(null);

    const hiddenFields = (
        <>
            <input type="hidden" name="_token" value={csrfToken} />
            <input type="hidden" name="state" value={state ?? ''} />
            <input type="hidden" name="auth_token" value={authToken} />
        </>
    );

    return (
        <>
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
                    <AuthorizationForm
                        fields={hiddenFields}
                        onSubmit={() => setAnswer('approve')}
                    >
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={answer !== null}
                            aria-busy={answer === 'approve'}
                        >
                            Approve
                        </Button>
                    </AuthorizationForm>

                    <AuthorizationForm
                        fields={hiddenFields}
                        deny
                        onSubmit={() => setAnswer('deny')}
                    >
                        <Button
                            type="submit"
                            variant="outline"
                            className="w-full"
                            disabled={answer !== null}
                            aria-busy={answer === 'deny'}
                        >
                            Deny
                        </Button>
                    </AuthorizationForm>
                </div>
            </div>
        </>
    );
}

Consent.layout = (props: ConsentProps) => ({
    title: `Connect ${props.clientName}`,
    description: `You are signed in as ${props.userName}.`,
});

/**
 * A plain HTML form, so Passport's redirect to the client works. Submitting does not prevent the post.
 */
function AuthorizationForm({
    fields,
    deny = false,
    onSubmit,
    children,
}: {
    fields: ReactNode;
    deny?: boolean;
    onSubmit: () => void;
    children: ReactNode;
}) {
    return (
        <form
            method="post"
            action="/oauth/authorize"
            className="flex-1"
            onSubmit={onSubmit}
        >
            {deny && <input type="hidden" name="_method" value="DELETE" />}
            {fields}
            {children}
        </form>
    );
}
