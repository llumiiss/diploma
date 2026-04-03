package com.subsciptions.diploma.web;

import org.springframework.http.HttpStatus;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.ResponseStatus;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;

@RestController
@RequestMapping("/api")
public class SubscriptionController {

    private final SubscriptionStore store = new SubscriptionStore();

    @PostMapping("/auth/send-code")
    @ResponseStatus(HttpStatus.CREATED)
    public MessageResponse sendCode(@RequestBody SendCodeRequest request) {
        store.rememberPendingEmail(request.email());
        return new MessageResponse("Kod wyslany na " + request.email() + " (demo: 123456)");
    }

    @PostMapping("/auth/verify")
    public AuthResponse verify(@RequestBody VerifyCodeRequest request) {
        boolean codeValid = "123456".equals(request.code()) && store.isPendingEmail(request.email());
        if (!codeValid) {
            return new AuthResponse(false, "Niepoprawny kod", null, false);
        }

        boolean admin = store.isAdmin(request.email());
        store.addKnownUser(request.email());
        return new AuthResponse(true, "Logowanie udane", request.email(), admin);
    }

    @GetMapping("/subscriptions")
    public List<SubscriptionItem> subscriptions() {
        return store.subscriptions();
    }

    @PostMapping("/subscriptions")
    @ResponseStatus(HttpStatus.CREATED)
    public SubscriptionItem addSubscription(@RequestBody NewSubscriptionRequest request) {
        return store.addSubscription(request.name(), request.monthlyCost());
    }

    @GetMapping("/users")
    public List<UserItem> users() {
        return store.users();
    }

    public record SendCodeRequest(String email) {
    }

    public record VerifyCodeRequest(String email, String code) {
    }

    public record NewSubscriptionRequest(String name, double monthlyCost) {
    }

    public record MessageResponse(String message) {
    }

    public record AuthResponse(boolean success, String message, String email, boolean admin) {
    }
}
