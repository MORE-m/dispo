import { FormEvent, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PageHeader from '@/components/heading-page';

type Catalog = {
    inventories: { id: number; name: string; code: string }[];
    media: {
        id: number;
        name: string;
        code: string;
        default_length_seconds: number | null;
    }[];
    rules: { inventory_id: number; advertising_medium_id: number }[];
    current_price_year: number;
};

type DraftPosition = {
    inventory_id: number | '';
    advertising_medium_id: number | '';
    length_seconds: number;
    total_spot_count: number;
    start_hour: number;
    end_hour_exclusive: number;
    day_group: string;
};

export default function StandardOfferEdit({
    catalog,
    schemaFingerprint,
}: {
    mode: 'create';
    offer: null;
    version: null;
    catalog: Catalog;
    schemaFingerprint: string;
}) {
    const [title, setTitle] = useState('Standardangebot');
    const [campaign, setCampaign] = useState('');
    const [position, setPosition] = useState<DraftPosition>({
        inventory_id: catalog.inventories[0]?.id ?? '',
        advertising_medium_id: '',
        length_seconds: 30,
        total_spot_count: 10,
        start_hour: 8,
        end_hour_exclusive: 9,
        day_group: 'mo_fr',
    });
    const [error, setError] = useState<string | null>(null);

    const mediaForInventory = useMemo(() => {
        if (position.inventory_id === '') {
            return [];
        }
        const allowed = new Set(
            catalog.rules
                .filter((rule) => rule.inventory_id === position.inventory_id)
                .map((rule) => rule.advertising_medium_id),
        );

        return catalog.media.filter((medium) => allowed.has(medium.id));
    }, [catalog, position.inventory_id]);

    function onSubmit(event: FormEvent) {
        event.preventDefault();
        setError(null);

        if (
            position.inventory_id === '' ||
            position.advertising_medium_id === ''
        ) {
            setError('Inventar und Werbemittel sind erforderlich.');
            return;
        }

        router.post(
            '/standardangebote',
            {
                title,
                campaign: campaign || null,
                order_discount_percent: '0',
                ae_enabled: false,
                schema_fingerprint: schemaFingerprint,
                positions: [
                    {
                        inventory_id: position.inventory_id,
                        advertising_medium_id: position.advertising_medium_id,
                        spot_method: 'average',
                        length_seconds: position.length_seconds,
                        total_spot_count: position.total_spot_count,
                        pricing_settlement_mode: 'normal',
                        time_ranges: [
                            {
                                start_hour: position.start_hour,
                                end_hour_exclusive: position.end_hour_exclusive,
                                day_group: position.day_group,
                                spot_count: position.total_spot_count,
                            },
                        ],
                        plan_rows: [
                            {
                                hour: position.start_hour,
                                day_group: position.day_group,
                            },
                        ],
                    },
                ],
            },
            {
                onError: (errors) => {
                    const first = Object.values(errors)[0];
                    setError(
                        typeof first === 'string'
                            ? first
                            : 'Speichern fehlgeschlagen.',
                    );
                },
            },
        );
    }

    return (
        <>
            <Head title="Standardangebot anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Standardangebot anlegen"
                    description="Kundenlose Vorlage, nur Spot Classic Average (BL-P4-03a)."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/standardangebote">Zurück</Link>
                        </Button>
                    }
                />
                <form
                    onSubmit={onSubmit}
                    className="mx-auto flex w-full max-w-2xl flex-col gap-4"
                    data-test="standard-offer-create-form"
                >
                    {error ? (
                        <p className="text-destructive text-sm">{error}</p>
                    ) : null}
                    <div className="space-y-2">
                        <Label htmlFor="title">Titel</Label>
                        <Input
                            id="title"
                            value={title}
                            onChange={(event) => setTitle(event.target.value)}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="campaign">
                            Kampagnentitel (optional)
                        </Label>
                        <Input
                            id="campaign"
                            value={campaign}
                            onChange={(event) =>
                                setCampaign(event.target.value)
                            }
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="inventory">Inventar</Label>
                        <select
                            id="inventory"
                            className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            value={position.inventory_id}
                            onChange={(event) =>
                                setPosition((current) => ({
                                    ...current,
                                    inventory_id: event.target.value
                                        ? Number(event.target.value)
                                        : '',
                                    advertising_medium_id: '',
                                }))
                            }
                        >
                            <option value="">Bitte wählen</option>
                            {catalog.inventories.map((inventory) => (
                                <option key={inventory.id} value={inventory.id}>
                                    {inventory.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="medium">Werbemittel</Label>
                        <select
                            id="medium"
                            className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            value={position.advertising_medium_id}
                            onChange={(event) => {
                                const id = event.target.value
                                    ? Number(event.target.value)
                                    : '';
                                const medium = catalog.media.find(
                                    (row) => row.id === id,
                                );
                                setPosition((current) => ({
                                    ...current,
                                    advertising_medium_id: id,
                                    length_seconds:
                                        medium?.default_length_seconds ??
                                        current.length_seconds,
                                }));
                            }}
                        >
                            <option value="">Bitte wählen</option>
                            {mediaForInventory.map((medium) => (
                                <option key={medium.id} value={medium.id}>
                                    {medium.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="length">Länge (Sekunden)</Label>
                            <Input
                                id="length"
                                type="number"
                                min={1}
                                value={position.length_seconds}
                                onChange={(event) =>
                                    setPosition((current) => ({
                                        ...current,
                                        length_seconds: Number(
                                            event.target.value,
                                        ),
                                    }))
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="spots">Spotanzahl</Label>
                            <Input
                                id="spots"
                                type="number"
                                min={1}
                                value={position.total_spot_count}
                                onChange={(event) =>
                                    setPosition((current) => ({
                                        ...current,
                                        total_spot_count: Number(
                                            event.target.value,
                                        ),
                                    }))
                                }
                            />
                        </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="space-y-2">
                            <Label htmlFor="start">Startstunde</Label>
                            <Input
                                id="start"
                                type="number"
                                min={0}
                                max={23}
                                value={position.start_hour}
                                onChange={(event) =>
                                    setPosition((current) => ({
                                        ...current,
                                        start_hour: Number(event.target.value),
                                    }))
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="end">Ende exklusiv</Label>
                            <Input
                                id="end"
                                type="number"
                                min={1}
                                max={24}
                                value={position.end_hour_exclusive}
                                onChange={(event) =>
                                    setPosition((current) => ({
                                        ...current,
                                        end_hour_exclusive: Number(
                                            event.target.value,
                                        ),
                                    }))
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="day">Tagesgruppe</Label>
                            <select
                                id="day"
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                value={position.day_group}
                                onChange={(event) =>
                                    setPosition((current) => ({
                                        ...current,
                                        day_group: event.target.value,
                                    }))
                                }
                            >
                                <option value="mo_fr">Mo–Fr</option>
                                <option value="sa">Sa</option>
                                <option value="so">So</option>
                            </select>
                        </div>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Preisjahr: {catalog.current_price_year} (aktive Liste)
                    </p>
                    <Button type="submit" data-test="standard-offer-save">
                        Entwurf anlegen
                    </Button>
                </form>
            </div>
        </>
    );
}
