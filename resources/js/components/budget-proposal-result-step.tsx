import {
    BudgetProposalPanel,
    type BudgetSpotProposal,
} from '@/components/budget-proposal-panel';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    wizardCardClass,
    wizardCardContentClass,
    wizardCardHeaderClass,
    wizardCardTitleClass,
} from '@/components/wizard-section';

export function BudgetProposalResultStep({
    proposal,
    proposalStatus,
    proposalLoading,
    busy,
    onApply,
    onEditInputs,
    onRecalculate,
    showApplyHint = false,
}: {
    proposal: BudgetSpotProposal | null;
    proposalStatus: string;
    proposalLoading: boolean;
    busy: boolean;
    onApply: () => void;
    onEditInputs: () => void;
    onRecalculate: () => void;
    showApplyHint?: boolean;
}) {
    if (proposalLoading) {
        return (
            <Card
                className={wizardCardClass}
                data-test="budget-proposal-loading"
            >
                <CardContent className={`${wizardCardContentClass} py-10`}>
                    <p className="text-muted-foreground text-center text-sm">
                        Budgetvorschlag wird berechnet …
                    </p>
                </CardContent>
            </Card>
        );
    }

    if (!proposal) {
        return (
            <Card className={wizardCardClass}>
                <CardContent className={`${wizardCardContentClass} py-10`}>
                    <p className="text-muted-foreground text-center text-sm">
                        Noch kein Budgetvorschlag berechnet. Gehe zu den
                        Konditionen und starte die Berechnung.
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className={wizardCardClass} data-test="budget-proposal-step">
            <CardHeader className={wizardCardHeaderClass}>
                <CardTitle className={wizardCardTitleClass}>
                    Budgetvorschlag
                </CardTitle>
            </CardHeader>
            <CardContent className={`${wizardCardContentClass} space-y-4`}>
                {showApplyHint ? (
                    <p
                        className="text-muted-foreground text-sm"
                        data-test="budget-apply-hint"
                    >
                        Übernimm zuerst den Vorschlag. Danach kannst du die
                        erzeugte Planung prüfen, bearbeiten und speichern.
                    </p>
                ) : null}
                <BudgetProposalPanel
                    proposal={proposal}
                    proposalStatus={proposalStatus}
                    busy={busy}
                    canApply={!proposal.insufficient_budget}
                    onApply={onApply}
                    onEditInputs={onEditInputs}
                    onRecalculate={onRecalculate}
                />
            </CardContent>
        </Card>
    );
}
