package com.aiso.web;

import com.aiso.domain.AppUser;
import com.aiso.repo.UserRepository;
import com.aiso.security.CurrentUser;
import com.aiso.security.TokenService;
import com.aiso.service.UserService;
import com.aiso.service.Views.UserView;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotBlank;
import org.springframework.http.HttpStatus;
import org.springframework.security.core.Authentication;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RequestMapping("/api/auth")
public class AuthController {

    public record LoginRequest(@NotBlank String userId, @NotBlank String password) {
    }

    public record LoginResponse(String token, int expiresInSeconds, UserView user) {
    }

    public record PasswordChange(@NotBlank String currentPassword, @NotBlank String newPassword) {
    }

    private final UserRepository users;
    private final PasswordEncoder encoder;
    private final TokenService tokens;
    private final UserService userService;

    public AuthController(UserRepository users, PasswordEncoder encoder, TokenService tokens, UserService userService) {
        this.users = users;
        this.encoder = encoder;
        this.tokens = tokens;
        this.userService = userService;
    }

    @PostMapping("/login")
    public LoginResponse login(@Valid @RequestBody LoginRequest req) {
        AppUser u = users.findById(req.userId().trim()).orElse(null);
        // identical message for unknown user, wrong password and disabled account
        if (u == null || !u.isActive() || !encoder.matches(req.password(), u.getPasswordHash())) {
            throw new ApiException(HttpStatus.UNAUTHORIZED, "Invalid user id or password");
        }
        return new LoginResponse(tokens.issue(u), tokens.ttlSeconds(), UserService.view(u));
    }

    @GetMapping("/me")
    public UserView me(Authentication auth) {
        AppUser u = users.findById(CurrentUser.from(auth).id()).orElseThrow();
        return UserService.view(u);
    }

    @PostMapping("/change-password")
    public void changePassword(Authentication auth, @Valid @RequestBody PasswordChange req) {
        userService.changeOwnPassword(CurrentUser.from(auth), req.currentPassword(), req.newPassword());
    }
}
