import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type MessageProps = {
    title: string;
    message: string;
    signOut: boolean;
};

export default function Message({ title, signOut }: MessageProps) {
    const { post, processing } = useForm({});

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        post('/logout');
    };

    return (
        <>
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
        </>
    );
}

Message.layout = (props: MessageProps) => ({
    title: props.title,
    description: props.message,
});
