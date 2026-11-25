<?php

/*
|--------------------------------------------------------------------------
| TV display dashboards (CLINICS TODAY / DOCTORS & PATIENTS)
|--------------------------------------------------------------------------
| Everything here is read from the environment (.env). Credentials are shared with the
| existing dashboard sync (DASHBOARD_API_USERNAME / DASHBOARD_API_PASSWORD).
*/

$floorNames = [];
foreach (explode(',', (string) env('TV_FLOOR_NAMES', '')) as $pair) {
    // "001:Ground Floor,002:First Floor"
    if (str_contains($pair, ':')) {
        [$code, $name] = array_map('trim', explode(':', $pair, 2));
        if ($code !== '' && $name !== '') {
            $floorNames[$code] = $name;
        }
    }
}

return [
    'timezone' => env('TV_TIMEZONE', 'Africa/Dar_es_Salaam'),

    'username' => env('DASHBOARD_API_USERNAME'),
    'password' => env('DASHBOARD_API_PASSWORD'),

    // API 1 - doctors of a clinic:   {url}/{clinicCode}
    'clinic_doctors_url' => env('TV_API_CLINIC_DOCTORS_URL', 'http://192.168.235.250/labsms/swagger/clinic_doctor_details'),
    // API 2 - clinics of a weekday:  {url}/{1..7}  (1 = Monday ... 7 = Sunday)
    'clinic_weekdays_url' => env('TV_API_CLINIC_WEEKDAYS_URL', 'http://192.168.235.250/labsms/swagger/clinic_weekdays'),
    // API 3 - visits of a day:       {url}/{YYYYMMDD}
    'dashboard_url' => env('TV_API_DASHBOARD_URL', env('DASHBOARD_API_BASE_URL', 'http://192.168.235.250/labsms/swagger/dashboard')),

    'connect_timeout' => (int) env('TV_API_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('TV_API_TIMEOUT', 20),

    // Seconds the finished response is cached, so many TVs / refreshes cost one HIS call.
    'live_cache_seconds' => (int) env('TV_CACHE_SECONDS', 10),
    // Clinic schedule and doctor lists hardly change during the day.
    'static_cache_seconds' => (int) env('TV_STATIC_CACHE_SECONDS', 21600),
    // If the HIS is down, the last good patient numbers are still served for this long (flagged stale).
    'max_stale_seconds' => (int) env('TV_MAX_STALE_SECONDS', 900),

    // floorCode => display name. Left empty on purpose: the meaning of a code is never guessed.
    'floor_names' => $floorNames,

    // Which doctors a clinic shows:
    //   'assigned' = every doctor Jeeva lists for the clinic (API 1). They are all "in the clinic" until the
    //                accountant switches one off (then the TV says absent). Default.
    //   'active'   = only doctors that have patients in the clinic today, plus the ones the accountant switched on.
    'doctor_visibility' => in_array(env('TV_DOCTOR_VISIBILITY', 'assigned'), ['assigned', 'active'], true) ? env('TV_DOCTOR_VISIBILITY', 'assigned') : 'assigned',

    // Only specialists / super specialists (API 1 doctQuali) are shown, except in clinics whose name contains
    // one of these words (comma separated), which keep every doctor.
    'all_qualification_clinics' => array_values(array_filter(array_map('trim', explode(',', (string) env('TV_ALL_QUALIFICATION_CLINICS', 'PHYSIOTHERAPY'))))),

    // Roles allowed to use the accountant control page (users.role). Comma separated.
    'control_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('TV_CONTROL_ROLES', 'Accountant,ED,DED,DICT'))))),

    // Waiting rule (same as the existing clinical dashboard, see SyncService):
    //  consulted = visitStatus "C"; or visitStatus "V" when ANY of consDoctor, consDoctName, doctConsDt,
    //              doctConsTm has data. The visit belongs to consDoctor (the billing doctor if it is empty)
    //  waiting   = everything else (e.g. "V" with all four empty), and it belongs to the billing doctor (doctCode)
    //  except    = the emergency clinic, where a visit with a recorded consTime counts as consulted.
    'completed_visit_status' => env('TV_COMPLETED_VISIT_STATUS', 'C'),
    // Visit statuses that mean "consulted" as soon as any consultation field has data (see above). "V" = vitals taken at triage, the patient is still to be seen by a doctor.
    'consult_data_statuses' => array_values(array_filter(array_map(fn ($s) => strtoupper(trim($s)), explode(',', (string) env('TV_CONSULT_DATA_STATUSES', 'V'))))),
    'emergency_clinic_name' => env('TV_EMERGENCY_CLINIC_NAME', 'MLG EMERGENCY MEDICINE'),
];
