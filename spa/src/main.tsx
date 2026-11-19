import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { RouterProvider } from "react-router-dom";

import "./globals.css";
import { Toaster } from "@/components/ui/sonner";
import { AuthProvider } from "@/lib/auth/auth-provider";
import { router } from "./router";

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <AuthProvider>
      <RouterProvider router={router} />
      <Toaster richColors position="top-right" />
    </AuthProvider>
  </StrictMode>,
);
