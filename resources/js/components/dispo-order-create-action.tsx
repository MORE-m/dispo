import { useState } from 'react';
import { DispoOrderCreateDialog } from '@/components/dispo-order-create-dialog';
import { Button } from '@/components/ui/button';
import type { DispoOrderRevisionContext } from '@/types/dispo-order';

export function DispoOrderCreateAction({
    calculationId,
    revision = null,
}: {
    calculationId: number;
    revision?: DispoOrderRevisionContext | null;
}) {
    const [open, setOpen] = useState(false);
    const isRevision = revision !== null;

    return (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(true)}
                data-test="dispo-order-create-open"
            >
                {isRevision
                    ? 'Korrigierten Dispoauftrag erstellen'
                    : 'Dispoauftrag anlegen'}
            </Button>
            <DispoOrderCreateDialog
                calculationId={calculationId}
                open={open}
                onOpenChange={setOpen}
                revision={revision}
            />
        </>
    );
}
