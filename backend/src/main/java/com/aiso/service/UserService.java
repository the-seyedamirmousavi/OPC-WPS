package com.aiso.service;

import com.aiso.domain.AppUser;
import com.aiso.domain.Operation;
import com.aiso.domain.Role;
import com.aiso.repo.OperationRepository;
import com.aiso.repo.UserRepository;
import com.aiso.security.CurrentUser;
import com.aiso.service.Views.UserView;
import com.aiso.web.ApiException;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.util.Comparator;
import java.util.List;

@Service
@Transactional
public class UserService {

    private final UserRepository users;
    private final OperationRepository operations;
    private final PasswordEncoder encoder;
    private final AuditService audit;

    public UserService(UserRepository users, OperationRepository operations, PasswordEncoder encoder, AuditService audit) {
        this.users = users;
        this.operations = operations;
        this.encoder = encoder;
        this.audit = audit;
    }

    @Transactional(readOnly = true)
    public List<UserView> list() {
        return users.findAll().stream()
                .sorted(Comparator.comparing((AppUser u) -> u.getRole().ordinal()).thenComparing(AppUser::getId))
                .map(UserService::view).toList();
    }

    public UserView create(String id, String fullName, Role role, String messengerId, String contact, String password,
                           CurrentUser actor) {
        if (role == Role.OWNER) {
            throw ApiException.badRequest("The OWNER account cannot be created here");
        }
        if (users.existsById(id)) {
            throw ApiException.conflict("User id '" + id + "' already exists");
        }
        checkPassword(password);
        AppUser u = new AppUser();
        u.setId(id.trim());
        u.setFullName(fullName.trim());
        u.setRole(role);
        u.setMessengerId(messengerId);
        u.setContactInfo(contact);
        u.setPasswordHash(encoder.encode(password));
        u.setMustChangePassword(true);
        users.save(u);
        audit.record(actor.id(), "USER_CREATED", "User", u.getId(), null, role, null);
        return view(u);
    }

    public UserView update(String id, String fullName, Role role, Boolean active, String messengerId, String contact,
                           CurrentUser actor) {
        AppUser u = find(id);
        if (u.getRole() == Role.OWNER && (role != null && role != Role.OWNER || Boolean.FALSE.equals(active))) {
            throw ApiException.forbidden("The OWNER account cannot be demoted or disabled");
        }
        if (role == Role.OWNER && u.getRole() != Role.OWNER) {
            throw ApiException.forbidden("Ownership cannot be granted through the API");
        }
        String before = u.getRole() + "/" + u.isActive();
        if (fullName != null && !fullName.isBlank()) u.setFullName(fullName.trim());
        if (role != null) u.setRole(role);
        if (messengerId != null) u.setMessengerId(messengerId);
        if (contact != null) u.setContactInfo(contact);
        if (active != null) {
            if (!active && u.isActive()) {
                long open = operations.findByAssignedUserId(id).stream().filter(o -> o.getStatus().isActive()
                        || o.getStatus() == com.aiso.domain.OperationStatus.BLOCKED).count();
                if (open > 0) {
                    throw ApiException.conflict("User " + id + " still has " + open
                            + " open task(s). Reassign or unassign them first.");
                }
            }
            u.setActive(active);
        }
        users.save(u);
        audit.record(actor.id(), "USER_UPDATED", "User", id, before, u.getRole() + "/" + u.isActive(), null);
        return view(u);
    }

    public void resetPassword(String id, String newPassword, CurrentUser actor) {
        AppUser u = find(id);
        if (u.getRole() == Role.OWNER && !actor.id().equals(id)) {
            throw ApiException.forbidden("Only the owner can reset the owner's password");
        }
        checkPassword(newPassword);
        u.setPasswordHash(encoder.encode(newPassword));
        u.setMustChangePassword(true);
        users.save(u);
        audit.record(actor.id(), "PASSWORD_RESET", "User", id, null, null, null);
    }

    public void changeOwnPassword(CurrentUser me, String current, String next) {
        AppUser u = find(me.id());
        if (!encoder.matches(current, u.getPasswordHash())) {
            throw ApiException.badRequest("Current password is incorrect");
        }
        checkPassword(next);
        u.setPasswordHash(encoder.encode(next));
        u.setMustChangePassword(false);
        users.save(u);
        audit.record(me.id(), "PASSWORD_CHANGED", "User", me.id(), null, null, null);
    }

    private AppUser find(String id) {
        return users.findById(id).orElseThrow(() -> ApiException.notFound("User " + id + " not found"));
    }

    private static void checkPassword(String p) {
        if (p == null || p.length() < 8) {
            throw ApiException.badRequest("Password must be at least 8 characters");
        }
    }

    public static UserView view(AppUser u) {
        return new UserView(u.getId(), u.getFullName(), u.getRole(), u.getMessengerId(), u.isActive(),
                u.getContactInfo(), u.isMustChangePassword(), u.getCreatedAt());
    }
}
