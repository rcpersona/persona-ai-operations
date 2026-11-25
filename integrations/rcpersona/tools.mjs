import { z } from "zod";

const id = z.number().int().min(1).max(999999999999999);
const date = z.iso.date().max(10);
const period = { from: date.optional(), to: date.optional() };
const paging = { limit: z.number().int().min(1).max(50).optional(), before_id: id.optional() };
const search = z.string().trim().min(1).max(120).optional();
const peopleforce = z.enum(["linked", "unlinked"]).optional();
const candidateFilters = {
  ...period,
  q: search,
  assigned_to_me: z.boolean().optional(),
  vacancy_id: id.optional(),
  recruiter_id: id.optional(),
  status: z.string().min(1).max(40).optional(),
  source: z.string().max(80).optional(),
  specialization: search,
  questionnaire: z.enum(["full", "quick_only", "none"]).optional(),
  email: z.string().trim().min(1).max(254).optional(),
  phone: z.string().trim().min(1).max(40).optional(),
};
const vacancyFilters = {
  ...period,
  q: search,
  country: search,
  specialization: search,
  category: search,
  status: z.enum(["actual", "closed", "hide"]).optional(),
  assigned_to_me: z.boolean().optional(),
};

const contacts = {
  include_contacts: z.boolean().optional(),
  view: z.enum(["summary", "detail"]).optional(),
};
const questionnaireFilters = {
  ...period, ...paging, q: search,
  type: z.enum(["quick", "full"]).optional(),
  recruiter_id: id.optional(),
  vacancy_id: id.optional(),
  include_answers: z.boolean().optional(),
};
const dimensions = z.enum(["status", "source", "recruiter", "period", "vacancy"]).optional();
const statistics = {
  ...candidateFilters, peopleforce,
  group_by: dimensions, then_by: dimensions, interval: z.enum(["day", "week", "month"]).optional(),
};

export const tools = {
  persona_whoami: ["Check the connected employee's current read permissions.", {}, () => "me"],
  persona_capabilities: ["Discover server version, visible fields, filters, and current employee permissions.", {}, () => "capabilities"],
  persona_search_candidates: [
    "Search authorized candidate application records by name, job title, assignment and other filters. Request include_contacts only when needed. Follow next_before_id even on an empty page. Use selection from statistics for drill-down.",
    { ...candidateFilters, ...paging, ...contacts, peopleforce, experience: z.string().trim().min(2).max(120).optional(),
      sort: z.enum(["id_desc", "id_asc"]).optional(), selection: z.enum(["personal", "team", "vacancy"]).optional() },
    () => "candidates",
  ],
  persona_get_candidate: ["Read a candidate record. Set view=detail for related vacancies and PeopleForce state. Set include_contacts only for a work request that needs contacts.", { id, ...contacts }, ({ id }) => `candidates/${id}`],
  persona_get_candidate_questionnaires: [
    "List one candidate's questionnaires. Full is the default. Use type=quick for the original application and include_answers when professional answers are needed.",
    { id, ...questionnaireFilters }, ({ id }) => `candidates/${id}/questionnaires`,
  ],
  persona_search_questionnaires: [
    "Search authorized full questionnaires or quick applications. Administrators can list incomplete and failed drafts with type=draft. Follow next_before_id.",
    { ...questionnaireFilters, type: z.enum(["quick", "full", "draft"]).optional(),
      draft_status: z.enum(["draft", "failed"]).optional() }, () => "questionnaires",
  ],
  persona_get_candidate_activity: [
    "Read the submission event, current status, and PeopleForce linkage. PeopleForce event history is unavailable.",
    { id }, ({ id }) => `candidates/${id}/activity`,
  ],
  persona_list_vacancies: [
    "List visible vacancies. Use status actual for active vacancies and assigned_to_me for vacancies assigned to the employee. Follow next_cursor even on empty pages.",
    { ...vacancyFilters, limit: paging.limit, cursor: z.string().max(160).optional(),
      sort: z.enum(["id_desc", "id_asc"]).optional() },
    () => "vacancies",
  ],
  persona_get_vacancy: ["Read one visible vacancy by WordPress post ID.", { id }, ({ id }) => `vacancies/${id}`],
  persona_lookup_employee: [
    "Find one internal work contact by exact first name, last name, full name, work email or employee ID. Provide one lookup criterion and ask for clarification if required.",
    { q: z.string().trim().min(2).max(254).optional(), employee_id: id.optional() }, () => "employees/lookup",
  ],
  persona_list_recruiters: ["List active recruiters available for management analytics. Follow next_before_id.", paging, () => "recruiters"],
  persona_candidate_stats: ["Aggregate candidate application records over the current personal or management scope. Dates refer to submission.", statistics, () => "statistics/candidates"],
  persona_recruiter_stats: ["Aggregate the current employee's assigned applications. Use period grouping and day, week or month intervals.", statistics, () => "statistics/recruiter"],
  persona_team_stats: ["Aggregate applications for the authorized management scope, grouped by recruiter by default.", statistics, () => "statistics/team"],
  persona_vacancy_stats: ["Count visible vacancies by current status. Dates refer to post creation.", vacancyFilters, () => "statistics/vacancies"],
  persona_vacancy_recruiting_stats: [
    "Aggregate authorized candidate applications and linked completed questionnaires for a specific visible vacancy. Dates refer to quick application creation.",
    { id, ...statistics }, ({ id }) => `vacancies/${id}/statistics`,
  ],
};

