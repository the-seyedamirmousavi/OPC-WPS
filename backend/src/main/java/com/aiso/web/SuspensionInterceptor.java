package com.aiso.web;

import com.aiso.domain.SystemStatus;
import com.aiso.security.CurrentUser;
import com.aiso.service.LanguageService;
import com.aiso.service.SettingsService;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.context.annotation.Configuration;
import org.springframework.http.HttpStatus;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.web.servlet.HandlerInterceptor;
import org.springframework.web.servlet.config.annotation.InterceptorRegistry;
import org.springframework.web.servlet.config.annotation.WebMvcConfigurer;

/** While the system is SUSPENDED only the OWNER may change data. Reads stay available. */
@Configuration
public class SuspensionInterceptor implements WebMvcConfigurer, HandlerInterceptor {

    private final SettingsService settings;
    private final LanguageService lang;

    public SuspensionInterceptor(SettingsService settings, LanguageService lang) {
        this.settings = settings;
        this.lang = lang;
    }

    @Override
    public void addInterceptors(InterceptorRegistry registry) {
        registry.addInterceptor(this).addPathPatterns("/api/**");
    }

    @Override
    public boolean preHandle(HttpServletRequest request, HttpServletResponse response, Object handler) throws Exception {
        if ("GET".equals(request.getMethod()) || "HEAD".equals(request.getMethod()) || "OPTIONS".equals(request.getMethod())) {
            return true;
        }
        if (request.getRequestURI().startsWith("/api/auth/")) {
            return true;
        }
        Authentication auth = SecurityContextHolder.getContext().getAuthentication();
        if (auth != null && auth.getPrincipal() instanceof CurrentUser u && u.isOwner()) {
            return true;
        }
        if (settings.get().getSystemStatus() == SystemStatus.SUSPENDED) {
            response.setStatus(HttpStatus.LOCKED.value());
            response.setContentType("application/json");
            response.setCharacterEncoding("UTF-8");
            response.getWriter().write("{\"message\":\"" + lang.forRequest("The system is suspended by the owner.") + "\"}");
            return false;
        }
        return true;
    }
}
