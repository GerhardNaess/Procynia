## CPV catalog

- `notice_cpv_codes` is a relation table only.
- `cpv_codes` is the master catalog table.
- `cpv_codes` stores both `description_en` and `description_no`.
- Admin UI resolves CPV readability through `cpv_codes` and keeps notice relationships unchanged.
