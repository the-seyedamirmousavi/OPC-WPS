/** A role key: the built-in ones (OWNER, MANAGER, USER_1..3, AGENT) or one the company defined. */
export type Role = string;
export type OpStatus =
  | "NOT_READY"
  | "READY"
  | "ASSIGNED"
  | "IN_PROGRESS"
  | "BLOCKED"
  | "COMPLETED"
  | "CANCELLED";
export type Mode = "ALGORITHM" | "LLM";

export const OP_STATUSES: OpStatus[] = [
  "NOT_READY",
  "READY",
  "ASSIGNED",
  "IN_PROGRESS",
  "BLOCKED",
  "COMPLETED",
  "CANCELLED",
];

export interface User {
  id: string;
  fullName: string;
  role: Role;
  /** the name of the role (for the company's own roles, what the company called it) */
  roleName: string;
  /** what the role lets the person do (the owner holds all of them); see lib/capabilities.ts */
  capabilities: string[];
  /** whether operations are assigned to this person */
  receivesTasks: boolean;
  messengerId: string | null;
  active: boolean;
  contactInfo: string | null;
  mustChangePassword: boolean;
  /** an AI agent: a virtual user (role AGENT) that cannot sign in */
  isAgent?: boolean;
  createdAt: string;
}

export interface PredView {
  id: string;
  name: string;
  status: OpStatus | null;
  type: "FINISH_TO_START" | "START_TO_START";
  mandatory: boolean;
  satisfied: boolean;
}

export interface Operation {
  id: string;
  name: string;
  projectId: string;
  projectName: string;
  projectRank: number;
  itemId: string | null;
  itemName: string | null;
  resourceId: string;
  resourceName: string;
  responsibleUserId: string | null;
  assignedUserId: string | null;
  assignedUserName: string | null;
  status: OpStatus;
  totalHours: number;
  directHours: number;
  progressPercent: number;
  plannedStart: string | null;
  plannedEnd: string | null;
  projectedStart: string | null;
  projectedEnd: string | null;
  assignedAt: string | null;
  startedAt: string | null;
  completedAt: string | null;
  completionApproved: boolean;
  blockReason: string | null;
  cancelReason: string | null;
  predecessors: PredView[];
  waitingFor: PredView[];
  delayed: boolean;
  delayHours: number;
  late: boolean;
  tailHours: number;
  description: string | null;
  /** manager FORCE on this operation (null when none) */
  forcedStart: string | null;
  forcedUserId: string | null;
  forceState: ForceState | null;
}

export interface OpReport {
  id: number;
  userId: string;
  userName: string | null;
  type: string;
  progressPercent: number | null;
  note: string | null;
  createdAt: string;
}

export interface OperationDetail {
  operation: Operation;
  reports: OpReport[];
  successors: PredView[];
}

export interface Progress {
  totalOperations: number;
  totalHours: number;
  completedHours: number;
  progressPercent: number;
  projectedEnd: string | null;
  remainingCriticalHours: number;
}

export interface ResourceLoad {
  id: string;
  name: string;
  capacity: number;
  busy: number;
  readyWaiting: number;
  notReady: number;
  active: boolean;
}

export interface UserLoad {
  userId: string;
  name: string;
  active: boolean;
  assigned: number;
  inProgress: number;
  blocked: number;
  loadHours: number;
  completed: number;
  completedHours: number;
}

export interface UserPerformance {
  userId: string;
  name: string;
  assigned: number;
  inProgress: number;
  blocked: number;
  completed: number;
  completedHours: number;
  onTimeRate: number | null;
  actualToPlanned: number | null;
  lateCount: number;
  blockReports: number;
}

export type StatusCounts = Record<OpStatus, number>;

export interface ManagerDashboard {
  statusCounts: StatusCounts;
  progress: Progress;
  assignmentMode: Mode;
  llmAvailable: boolean;
  llmModel: string;
  pendingProposals: number;
  ready: Operation[];
  blocked: Operation[];
  delayed: Operation[];
  inProgress: Operation[];
  criticalPath: Operation[];
  resources: ResourceLoad[];
  users: UserLoad[];
  recentReports: OpReport[];
  projects: ProjectSummary[];
  schedule: PlanMetrics;
  naiveSchedule: PlanMetrics;
}

export interface AuditEvent {
  eventId: string;
  occurredAt: string;
  actorId: string | null;
  action: string;
  entityType: string;
  entityId: string | null;
  previousValue: string | null;
  newValue: string | null;
  reason: string | null;
}

export interface ImportSummary {
  id: number;
  importedAt: string;
  actorId: string;
  fileName: string | null;
  format: string | null;
  status: string;
  errorCount: number;
  summary: string | null;
}

export interface OwnerDashboard {
  projectId: string | null;
  projectName: string | null;
  systemStatus: "ACTIVE" | "SUSPENDED";
  dataVersion: string | null;
  configurationVersion: number;
  assignmentMode: Mode;
  llmAvailable: boolean;
  llmModel: string;
  messengerPlatform: string;
  requireCompletionApproval: boolean;
  maxActiveTasksPerUser: number;
  usersByRole: Partial<Record<Role, number>>;
  roleNames: Record<Role, string>;
  activeUsers: number;
  statusCounts: StatusCounts;
  progress: Progress;
  failedNotifications: number;
  recentImports: ImportSummary[];
  recentAudit: AuditEvent[];
}

export interface Notification {
  id: number;
  kind: string;
  message: string;
  relatedOperationId: string | null;
  createdAt: string;
  readAt: string | null;
  deliveryStatus: string;
  deliveryError: string | null;
}

export interface UserDashboard {
  userId: string;
  name: string;
  statusCounts: StatusCounts;
  activeTasks: Operation[];
  recentCompleted: Operation[];
  performance: UserPerformance;
  unreadNotifications: number;
  notifications: Notification[];
}

export interface Proposal {
  id: number;
  runId: string;
  operationId: string;
  operationName: string | null;
  projectName: string | null;
  projectRank: number;
  resourceName: string | null;
  userId: string;
  userName: string;
  source: Mode;
  reason: string | null;
  plannedStart: string | null;
  plannedEnd: string | null;
  status: "PENDING" | "APPROVED" | "REJECTED" | "SUPERSEDED";
  decisionNote: string | null;
  hours: number;
  tailHours: number;
}

export interface Skipped {
  operationId: string;
  operationName: string;
  reason: string;
}

export interface Run {
  id: string;
  requestedMode: Mode;
  effectiveMode: Mode;
  fallback: boolean;
  model: string | null;
  summary: string | null;
  warnings: string[];
  unassigned: Skipped[];
  proposals: Proposal[];
  createdAt: string;
  durationMs: number;
  inputTokens: number | null;
  outputTokens: number | null;
  requestedBy: string;
}

export interface Settings {
  projectId: string | null;
  projectName: string | null;
  ownerId: string | null;
  managerId: string | null;
  messengerPlatform: string;
  systemStatus: "ACTIVE" | "SUSPENDED";
  dataVersion: string | null;
  configurationVersion: number;
  assignmentMode: Mode;
  maxActiveTasksPerUser: number;
  requireCompletionApproval: boolean;
  llmAvailable: boolean;
  llmModel: string;
  /** The AI token itself is never sent: only whether one is set, where it comes from and its last four characters. */
  llmKeySet: boolean;
  llmKeySource: "settings" | "environment" | null;
  llmKeyHint: string | null;
  llmBaseUrl: string;
  /** The Bale bot token: only whether one is set, where it comes from and its last four characters. */
  baleKeySet: boolean;
  baleKeySource: "settings" | "environment" | null;
  baleKeyHint: string | null;
  language: "fa" | "en";
}

export interface ImportIssue {
  sheet: string;
  row: number;
  column: string;
  message: string;
}

export interface ProjectRef {
  id: string;
  name: string;
  priority: number;
}

export interface ImportResult {
  status: "REJECTED" | "VALID" | "APPLIED" | "PRIORITY_REQUIRED";
  format: string;
  projectId: string | null;
  projectName: string | null;
  newProject: boolean;
  activeProjects: ProjectRef[];
  impact: Impact | null;
  errors: ImportIssue[];
  warnings: string[];
  counts: { entity: string; created: number; updated: number }[];
  newUsers: { userId: string; fullName: string; temporaryPassword: string }[];
}

export interface ImportLog {
  id: number;
  importedAt: string;
  actorId: string;
  fileName: string | null;
  format: string | null;
  status: string;
  errorCount: number;
  summary: string | null;
  details: string | null;
}

// ---- multiple projects & schedule ----------------------------------------------------------------------

export interface ProjectSummary {
  id: string;
  name: string;
  priority: number;
  status: "ACTIVE" | "ARCHIVED";
  dueDate: string | null;
  createdAt: string;
  totalOperations: number;
  statusCounts: StatusCounts;
  totalHours: number;
  completedHours: number;
  progressPercent: number;
  openOperations: number;
  finishHours: number;
  finishAt: string | null;
  tardinessHours: number;
}

export interface ProjectPlan {
  projectId: string;
  name: string;
  rank: number;
  openOperations: number;
  startHours: number;
  finishHours: number;
  finishAt: string | null;
  dueDate: string | null;
  tardinessHours: number;
  rule: string | null;
}

export interface ResourceMetric {
  resourceId: string;
  name: string;
  capacity: number;
  busyHours: number;
  wasteHours: number;
  utilizationPercent: number;
}

export interface PlanMetrics {
  makespanHours: number;
  finishAt: string | null;
  busyHours: number;
  wasteHours: number;
  utilizationPercent: number;
  projects: ProjectPlan[];
  resources: ResourceMetric[];
  candidatesTried: number;
}

export interface ProjectDelta {
  projectId: string;
  name: string;
  currentRank: number | null;
  proposedRank: number;
  currentFinishHours: number | null;
  proposedFinishHours: number;
  deltaHours: number | null;
}

export interface Impact {
  current: PlanMetrics;
  proposed: PlanMetrics;
  naive: PlanMetrics;
  deltas: ProjectDelta[];
  wasteSavedVsNaive: number;
  makespanSavedVsNaive: number;
}

export interface SchedulingOverview {
  optimized: PlanMetrics;
  naive: PlanMetrics;
  projects: ProjectSummary[];
}

// ---------------------------------------------------------------- manager FORCE

/** HONOURED: in force and met; NOT_HONOURED: impossible now (see conflict); DONE: the operation started or finished */
export type ForceState = "HONOURED" | "NOT_HONOURED" | "DONE";

export interface Finding {
  kind: string;
  operationId: string | null;
  /** conflicts: true = impossible for every schedule; false = the planner found no schedule (one may exist) */
  proven: boolean;
  related: string[];
  message: string;
}

export interface ForceView {
  operationId: string;
  operationName: string;
  projectId: string;
  projectName: string;
  projectRank: number;
  resourceName: string;
  operationStatus: OpStatus;
  forcedStart: string | null;
  forcedUserId: string | null;
  forcedUserName: string | null;
  reason: string | null;
  createdBy: string;
  createdAt: string;
  state: ForceState;
  plannedStart: string | null;
  plannedEnd: string | null;
  conflict: Finding | null;
}

export interface ForceBoard {
  forces: ForceView[];
  conflicts: Finding[];
  escalated: number;
  baselineSavedAt: string | null;
}

export interface MovedOp {
  operationId: string;
  name: string;
  projectName: string;
  committed: boolean;
  before: string;
  after: string;
  shiftHours: number;
}

export interface ProjectShift {
  projectId: string;
  name: string;
  priority: number;
  before: string | null;
  after: string | null;
  deltaHours: number;
}

export interface ForceImpact {
  feasible: boolean;
  operationId: string;
  currentStart: string | null;
  plannedStart: string | null;
  plannedEnd: string | null;
  forcedUserId: string | null;
  conflicts: Finding[];
  warnings: Finding[];
  movedCount: number;
  committedMoved: number;
  totalShiftHours: number;
  moved: MovedOp[];
  projects: ProjectShift[];
  wasteBefore: number;
  wasteAfter: number;
}

// ---- departments (owner panels; suggestions to the manager) -------------------------------------------------------

export type DepartmentRole = "HEAD" | "MEMBER";
export type DepartmentPermission = "PANEL_VIEW" | "RESOURCES_MANAGE" | "SUGGESTIONS_SUBMIT";
export type SuggestionStatus = "PENDING" | "ACCEPTED" | "REJECTED";

export interface DepartmentSummary {
  id: number;
  name: string;
  /** special role of the department: HR or PRODUCTION, or null */
  code: string | null;
  description: string | null;
  status: "ACTIVE" | "INACTIVE";
  sortOrder: number;
  memberCount: number;
  resourceCount: number;
  staleResourceCount: number;
  pendingSuggestions: number;
  /** the owner's directory only */
  heads?: { userId: string; fullName: string | null }[];
  /** the owner's directory only: HR has activeUsers; production has ready, inProgress, blocked; others null */
  metrics?: Record<string, number> | null;
}

export interface DepartmentMemberView {
  userId: string;
  fullName: string | null;
  role: DepartmentRole;
  active: boolean;
}

export interface DepartmentResourceView {
  id: string;
  name: string;
  resourceType: string | null;
  capacity: number;
  status: string;
  description: string | null;
  reviewedAt: string | null;
  stale: boolean;
}

export interface ResourceChoice {
  id: string;
  name: string;
  departmentId: number | null;
  departmentName: string | null;
}

export interface DepartmentSuggestion {
  id: number;
  departmentId: number;
  departmentName: string | null;
  title: string;
  body: string;
  status: SuggestionStatus;
  resourceId: string | null;
  resourceName: string | null;
  authorId: string;
  authorName: string | null;
  createdAt: string;
  decidedAt: string | null;
  decidedBy: string | null;
  managerNote: string | null;
}

/** A department in the main menu of the person looking at it. */
export interface DepartmentMenuItem {
  id: number;
  name: string;
  code: string | null;
  status: "ACTIVE" | "INACTIVE";
  isHr: boolean;
  /** the person heads this department */
  headOf: boolean;
}

/** What the person looking at a panel may do. The server checks the same rules. */
export interface DepartmentAbilities {
  manageDepartment: boolean;
  manageMembers: boolean;
  managePermissions: boolean;
  assignResources: boolean;
  viewUsers: boolean;
  viewAudit: boolean;
  viewAgents: boolean;
  viewImports: boolean;
  viewManagerDashboard: boolean;
  viewPlanning: boolean;
  manageResources: boolean;
  submitSuggestions: boolean;
}

export interface DepartmentPanel extends DepartmentSummary {
  isHr: boolean;
  /** null for the owner; a member sees their own role only */
  myRole: DepartmentRole | null;
  abilities: DepartmentAbilities;
  members: DepartmentMemberView[];
  resources: DepartmentResourceView[];
  suggestions: DepartmentSuggestion[];
  reviewDays: number;
  /** owner only */
  permissions?: Record<DepartmentRole, DepartmentPermission[]>;
  /** owner only */
  permissionCatalog?: DepartmentPermission[];
  /** owner only */
  allResources?: ResourceChoice[];
}

// ---- AI agents: virtual users, made by the owner or by a manager through the assistant -----------------------------

export interface AgentTask {
  id: number;
  agentId: string;
  createdBy: string;
  instruction: string;
  status: "PENDING" | "RUNNING" | "DONE" | "FAILED";
  result: string | null;
  error: string | null;
  createdAt: string;
  finishedAt: string | null;
}

export interface Agent {
  id: string;
  name: string;
  instructions: string | null;
  /** department slugs the agent may read: hr, production, dept-{id} */
  scope: string[];
  active: boolean;
  createdBy: string | null;
  createdAt: string | null;
  tasks: AgentTask[];
}

// ---- what the owner lets the assistant do, and the requests waiting for the owner ----------------------------------

export type AiPolicyMode = "DENY" | "AUTO" | "SELF" | "OWNER";

export interface AiPolicies {
  writesEnabled: boolean;
  actions: { kind: "production" | "hr" | "general"; action: string; risk: "low" | "medium" | "high"; mode: AiPolicyMode; default: AiPolicyMode }[];
}

export interface AiApproval {
  id: number;
  department: string;
  operation: string;
  params: Record<string, unknown>;
  reason: string | null;
  risk: "low" | "medium" | "high";
  status: string;
  approval: string;
  expires_at: string;
  requested_by: string;
  requested_by_name: string;
}
