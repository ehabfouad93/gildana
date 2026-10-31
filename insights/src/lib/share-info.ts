import { PLATFORM_SETUP } from "@/lib/platform-setup";
import { PLATFORMS, PLATFORM_META } from "@/lib/platforms";
import { setting } from "@/lib/settings";
import type { ShareInfo } from "@/components/share-access";

/** Platforms where Gildana has published how to share access with it (its Business / MCC / BC id is set). */
export function shareInfo(): ShareInfo[] {
  return PLATFORMS.flatMap((p) => {
    const s = PLATFORM_SETUP[p].share;
    const id = s ? setting(s.idKey) : "";
    return s && id ? [{ platform: p, label: PLATFORM_META[p].label, agencyId: id, idLabel: s.idLabel, steps: s.steps, accountIdHelp: s.accountIdHelp }] : [];
  });
}
