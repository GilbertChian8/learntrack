import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';

type MessageProps = {
    title: string;
    message: string;
    signOut: boolean;
};

export default function Message({ title, message, signOut }: MessageProps) {
    const { post, processing } = useForm({});

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        post('/logout');
    };

    return (
        <AuthLayout title={title} description={message}>
            <Head title={title} />

            {signOut && (
                <form onSubmit={submit}>
                    <Button
                        type="submit"
                        variant="outline"
                        className="w-full"
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Sign out
                    </Button>
                </form>
            )}
        </AuthLayout>
    );
}
