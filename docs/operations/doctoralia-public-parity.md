# Doctoralia public parity runbook

Status: `EXTERNAL_PUBLIC_PARITY_OPEN`

Owner issue: #751

This runbook governs reconciliation of NUVANX Doctoralia profiles with the current website/repository service SSOT. Doctoralia is an external publication surface; it does not redefine the NUVANX clinical catalog.

## Write policy — 2026-08-28 correction

Safe, non-destructive corrections to the Goya clinic profile and its public service mappings are now authorized. The previous all-or-nothing write block was too broad: fresh public evidence proves that profile fields have propagated while service aggregation remains stale, so the service cleanup can proceed without first deleting either duplicate direction.

### Allowed now

- edit the Goya clinic profile in Clinic Cloud → Doctoralia → Lista de perfiles → Editar perfil;
- edit Goya public service visibility, names and descriptions;
- add missing canonical Goya services when the Doctoralia service type is verified;
- edit professional↔Goya service mappings when the current account has permission (Doctoralia PRO, the professional's own account, or Doctoralia/Clinic Cloud support);
- edit online-booking visibility for those services/specialists;
- capture before/after evidence and validate publicly after each mutation batch.

### Still blocked

- delete or merge direction `53333` / `49168`;
- change or delete agenda hours/agendas;
- globally deactivate/delete Clinic Cloud services merely to clean Doctoralia;
- remove professionals;
- change `Responsable sanitario` from this workstream;
- mutate Chamberí before its authenticated admin export is complete.

Direction ownership is therefore required for **direction retirement/merge**, not for non-destructive Goya profile/service cleanup.

## Where Doctoralia is actually configured inside Clinic Cloud

Clinic Cloud's current official help documents two related entry points:

1. **Top-right profile menu → Configuración → Doctoralia**: opens the Doctoralia module. From `Lista de perfiles` an administrator can edit the clinic profile, including information, services, specialties, images and other public fields.
2. **Top-right profile menu → Configuración → ADMINISTRADOR → Sincronización Doctoralia**: manages the Clinic Cloud↔Doctoralia synchronization when the account is integrated. This block is visible only to users with Administrator role.

If the Doctoralia module or `Sincronización Doctoralia` is missing, do not conclude that the integration does not exist. The official Clinic Cloud help states that the module is shown only to an integrated professional or an administrator of an integrated clinic. Request/repair access through Clinic Cloud support and include professional name, email, specialty/service to map, registration/collegiate number where applicable, phone and agenda name.

Do not guess an internal URL for `Sincronización Doctoralia`; navigate from the UI because the route is account/role dependent.

## Current observed state — 2026-08-28

### Salamanca–Goya

- Doctoralia facility: `54924`.
- Registration: `CS20073`.
- Public profile: `https://www.doctoralia.es/clinicas/nuvanx-medicina-estetica-laser-sede-goya`.
- Two admin direction records exist for the same physical location:
  - `53333`: Goya-specific website URL, 16 editable service rows.
  - `49168`: NUVANX home URL, 7 editable service rows; exact first-seven-row subset of `53333`.
- Public Doctoralia now also exposes the same Fernán González 26 location twice.
- `53333` is still the stronger candidate, but canonical direction ownership is **not proven**; do not merge/delete either direction yet.
- The main public profile has propagated several recent edits: specialties are `Medicina estética`, `Enfermería`, `Geriatría`, `Nutrición y dietética`; equipment now includes Endoláser/LaseMAR/DEKA SmartLipo; and the public Doctoralia responsible-person field shows Javier Rivera Tejeda.
- The public FAQ/service aggregation is still stale at 13 services and still exposes `Visita Medicina Complementaria y terapias alternativas`, `Coolsculpting`, `Tratamiento profesional despigmentante facial`, `Tratamiento con dermapen`, `Diatermia`, `Fototerapia`, `HIFU (Facial)` and `HIFU (Corporal)`.
- Treatment-search/professional surfaces still expose HIFU, Dermapen, Fototerapia, Maderoterapia, Micropigmentación de cejas, Luz pulsada IPL and despigmentante facial.
- Clinic Cloud agenda `200346` (`ESTETICIEN`, user `GOSIA`) is integrated and accepts the matching legacy internal services, which is a direct operational clue for why those public service associations remain live.

### Chamberí

- Doctoralia facility: `47595`.
- Registration: `CS20144`.
- Public profile: `https://www.doctoralia.es/clinicas/nuvanx-medicina-estetica-laser`.
- Full authenticated admin export remains pending.

## Canonical service projection

The top-level target set is owned by `inc/data/treatment-hub-schema.json` and must be identical across both Doctoralia clinics unless a documented operational exception exists:

1. Endolift® Facial
2. Endoláser Corporal
3. Láser CO₂ Fraccionado
4. Plataforma EXION® BTL
5. Medicina Estética Facial
6. Bioestimulación de colágeno
7. BTL EXILITE™ IPL
8. Neuromodulador
9. Ácido Hialurónico
10. Rinomodelación

Bookable subservices may exist only when backed by the current tariff/route catalog. `Primera consulta gratuita` and `Consulta de revisión` are appointment types, not clinical-treatment parity rows.

## Execution A — open the correct Clinic Cloud / Doctoralia surfaces

Use an Administrator account.

1. Open the top-right profile menu.
2. Enter `Configuración`.
3. Verify the `Administrador` block is visible.
4. Open `Sincronización Doctoralia` and capture all visible integration rows before changing anything.
5. Return to the top-right menu and open `Configuración → Doctoralia`.
6. Open `Lista de perfiles`.
7. Select `NUVANX Medicina Estética Láser (Sede Goya)` and confirm the profile is the one whose public URL ends in `/nuvanx-medicina-estetica-laser-sede-goya`.
8. Open `Editar perfil`.
9. Capture the `Servicios` and `Especialidades` state before saving.

If step 4 or 5 is not available, use the in-app Help/Support control. Report that the clinic has four agendas showing the green `Integrada` indicator but the current administrator account cannot see the Doctoralia configuration/synchronization module. Ask support to expose/confirm the integrated-clinic admin mapping. Do not continue by guessing hidden URLs.

## Execution B — correct the Goya center service list first

In `Doctoralia → Lista de perfiles → Goya → Editar perfil → Servicios`:

### Keep/add canonical public services

Map the current canonical service set using verified Doctoralia types only. Known verified candidates include `Técnica Endolift` and `Láser de CO2`.

The target includes Endolift® facial, Endoláser corporal, Láser CO₂ fraccionado, EXION® BTL, Medicina Estética Facial, Bioestimulación de colágeno, BTL EXILITE™ IPL, Neuromodulador, Ácido Hialurónico and Rinomodelación.

Do not invent a Doctoralia taxonomy equivalence for Endoláser/EXION/EMFUSION. If the selector has no clinically correct type, leave the mapping pending and request catalog support.

### Remove from public Doctoralia service publication

Unless explicitly re-approved by the current clinical/business owner:

- HIFU facial;
- HIFU corporal;
- CoolSculpting;
- Dermapen;
- Medicina Complementaria / terapias alternativas.

Review before retaining/mapping:

- Diatermia;
- Fototerapia;
- Maderoterapia;
- Micropigmentación de cejas;
- Tratamiento profesional despigmentante facial;
- Luz pulsada IPL.

Do not turn off/delete the underlying Clinic Cloud service globally merely to remove it from Doctoralia.

Save this center-profile batch and validate the public Goya FAQ/service list before moving on.

## Execution C — clean professional↔Goya mappings

The center profile alone is insufficient. Gosia's integrated agenda is currently a live source of legacy service eligibility.

For `Gosia Ledniowska Janina`, remove the Goya publication/booking mapping for every legacy service that has been classified `REMOVE_LEGACY`.

Access rules from Clinic Cloud's official Doctoralia help:

- with a basic profile, an administrator can edit the clinic profile but cannot edit professional profiles;
- the professional can edit their own profile;
- an administrator needs Doctoralia PRO to edit professional profiles.

Therefore use one of these supported paths:

1. Doctoralia PRO administrator → edit Gosia's profile/mappings;
2. Gosia's own integrated Doctoralia account → edit her Goya services;
3. Clinic Cloud/Doctoralia support → request the mapping removal, listing the professional, service and agenda `200346`.

Do not remove Gosia, delete agenda `200346`, change its hours or globally deactivate services as a substitute for removing Doctoralia publication mappings.

## Execution D — booking channels

Open `Doctoralia → Canales de reserva`.

For Goya, ensure only approved services/specialists are offered for online booking. Clinic Cloud's current help states that Doctoralia PRO can select which services and specialists are available for reservation and that Doctoralia reservations are written back automatically to Clinic Cloud.

Use this screen as an additional mapping check: if a legacy service still appears as bookable for Gosia/Goya, its public mapping is not fully cleaned.

## Execution E — duplicate directions

Do not resolve `53333` vs `49168` during the service-cleanup batch.

After service/public parity is corrected:

1. use `Administrador → Sincronización Doctoralia` plus agenda/future-appointment evidence to determine which direction owns the live operation;
2. inspect both directions for unique appointments, professionals and mappings;
3. only if `49168` has no unique dependencies should it be retired/merged;
4. if Doctoralia warns that deleting a direction affects schedules/appointments, stop and request a support-side merge.

The duplicate-direction task remains destructive and separately gated.

## Execution F — Clinic Cloud internal services

`Configuración → Servicios` is the internal Clinic Cloud catalog. Do not use global deactivation as the first Doctoralia cleanup mechanism.

If NUVANX later confirms that a legacy treatment is no longer delivered at all, then the service can be set `De baja` through `Configuración → Servicios → edit service → Estado: De baja`. That is a separate operational decision because it can change which services are available for internal appointments.

`Configuración → Asignaciones` controls user↔specialty eligibility, and Clinic Cloud documents that this determines which services can appear for a user's agenda. Do not remove assignments only to manipulate Doctoralia SEO; change them only if the real professional/specialty relationship is wrong.

## Price handling

`tariff-catalog.json` is the price SSOT. If Doctoralia supports only integer display pricing, classify the difference as `PRICE_DISPLAY_RECONCILE`; do not change the NUVANX tariff SSOT.

## Responsable sanitario

No mutation is authorized from this workstream. Doctoralia currently displays Javier Rivera Tejeda, but the official `legal_healthcare_responsible` for `CS20073` remains separately unverified. Do not promote a Doctoralia field into website legal/schema truth.

## Mandatory public acceptance

After every controlled batch validate:

1. Goya primary clinic FAQ/services;
2. Doctoralia treatment-search pages for every removed legacy service;
3. Gosia's Goya service list;
4. online-booking services/specialists;
5. specialties/equipment/NAP remain correct;
6. both duplicate Goya directions remain untouched until the destructive-direction gate is satisfied.

Acceptance requires exact service identity comparison, not matching service counts.

## Required classifications

- `KEEP_CANONICAL`
- `ADD_MISSING`
- `REMOVE_LEGACY`
- `RENAME_CANONICAL`
- `REVIEW_DUPLICATE`
- `PRICE_DISPLAY_RECONCILE`
- `LOCATION_EXCEPTION`
- `APPOINTMENT_TYPE`
- `CATALOG_TYPE_PENDING`
- `LEGAL_VERIFY_ONLY`

Do not mark #751 complete until the public surfaces, not merely the editor, reflect the reconciled state.
