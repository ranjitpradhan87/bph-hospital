/** The app follows the system light/dark setting (CSS), so there is no theme state to manage. */
export const useTheme = () => ({ theme: "system" as const, setTheme: (_: string) => {} });
