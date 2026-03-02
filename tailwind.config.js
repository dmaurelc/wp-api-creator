/** @type {import('tailwindcss').Config} */
module.exports = {
  prefix: "tw-",
  important: "#wp-api-creator-app",
  content: ["./src/frontend/**/*.{js,jsx,ts,tsx}"],
  theme: {
    extend: {
      colors: {
        // Shadcn/HeroUI-inspired zinc neutral palette
        foreground: {
          DEFAULT: "#18181b", // zinc-900
          muted: "#71717a", // zinc-500
          subtle: "#a1a1aa", // zinc-400
        },
        border: {
          DEFAULT: "#e4e4e7", // zinc-200
          hover: "#d4d4d8", // zinc-300
        },
        background: {
          DEFAULT: "#ffffff",
          subtle: "#fafafa", // zinc-50
          muted: "#f4f4f5", // zinc-100
        },
        // Accent color - professional black (zinc-900)
        accent: {
          DEFAULT: "#18181b", // zinc-900
          foreground: "#ffffff",
          hover: "#27272a", // zinc-800
          muted: "#f4f4f5", // zinc-100
          subtle: "#fafafa", // zinc-50
        },
        // Semantic colors
        success: {
          DEFAULT: "#22c55e",
          muted: "#f0fdf4",
          foreground: "#15803d",
        },
        destructive: {
          DEFAULT: "#ef4444",
          muted: "#fef2f2",
          foreground: "#dc2626",
        },
        warning: {
          DEFAULT: "#f59e0b",
          muted: "#fffbeb",
          foreground: "#d97706",
        },
        info: {
          DEFAULT: "#3b82f6",
          muted: "#eff6ff",
          foreground: "#2563eb",
        },
      },
      fontFamily: {
        sans: ["Inter", "system-ui", "-apple-system", "sans-serif"],
        mono: ["JetBrains Mono", "Fira Code", "Menlo", "monospace"],
      },
      borderRadius: {
        lg: "0.5rem",
        xl: "0.75rem",
      },
      fontSize: {
        xxs: "0.625rem",
      },
      boxShadow: {
        sm: "0 1px 2px 0 rgb(0 0 0 / 0.03)",
        DEFAULT:
          "0 1px 3px 0 rgb(0 0 0 / 0.04), 0 1px 2px -1px rgb(0 0 0 / 0.04)",
        md: "0 4px 6px -1px rgb(0 0 0 / 0.04), 0 2px 4px -2px rgb(0 0 0 / 0.04)",
      },
    },
  },
  corePlugins: {
    preflight: false,
  },
  plugins: [],
};
