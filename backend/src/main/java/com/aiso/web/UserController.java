package com.aiso.web;

import com.aiso.domain.Role;
import com.aiso.security.CurrentUser;
import com.aiso.service.UserService;
import com.aiso.service.Views.UserView;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Pattern;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PatchMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;

@RestController
@RequestMapping("/api/users")
public class UserController {

    public record CreateUser(@NotBlank @Pattern(regexp = "[A-Za-z0-9_.-]{2,64}") String id, @NotBlank String fullName,
                             @NotNull Role role, String messengerId, String contactInfo, @NotBlank String password) {
    }

    public record UpdateUser(String fullName, Role role, Boolean active, String messengerId, String contactInfo) {
    }

    public record ResetPassword(@NotBlank String newPassword) {
    }

    private final UserService users;

    public UserController(UserService users) {
        this.users = users;
    }

    @GetMapping
    @PreAuthorize(Access.MANAGEMENT)
    public List<UserView> list() {
        return users.list();
    }

    @PostMapping
    @PreAuthorize(Access.OWNER)
    public UserView create(Authentication auth, @Valid @RequestBody CreateUser req) {
        return users.create(req.id(), req.fullName(), req.role(), req.messengerId(), req.contactInfo(), req.password(),
                CurrentUser.from(auth));
    }

    @PatchMapping("/{id}")
    @PreAuthorize(Access.OWNER)
    public UserView update(Authentication auth, @PathVariable String id, @RequestBody UpdateUser req) {
        return users.update(id, req.fullName(), req.role(), req.active(), req.messengerId(), req.contactInfo(),
                CurrentUser.from(auth));
    }

    @PostMapping("/{id}/reset-password")
    @PreAuthorize(Access.OWNER)
    public void resetPassword(Authentication auth, @PathVariable String id, @Valid @RequestBody ResetPassword req) {
        users.resetPassword(id, req.newPassword(), CurrentUser.from(auth));
    }
}
