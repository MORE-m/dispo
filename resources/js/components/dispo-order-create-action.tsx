import { useState } from 'react';
import { DispoOrderCreateDialog } from '@/components/dispo-order-create-dialog';
import { Button } from '@/components/ui/button';

export function DispoOrderCreateAction({
    calculationId,
}: {
    calculationId: number;
}) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(true)}
                data-test="dispo-order-create-open"
            >
                Dispoauftrag anlegen
            </Button>
            <DispoOrderCreateDialog
                calculationId={calculationId}
                open={open}
                onOpenChange={setOpen}
            />
        </>
    );
}
