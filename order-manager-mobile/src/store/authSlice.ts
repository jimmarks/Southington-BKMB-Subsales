import { createSlice, PayloadAction } from '@reduxjs/toolkit';

interface AuthState {
  user: null | { id: string; name: string; email: string };
  isLoggedIn: boolean;
}

const initialState: AuthState = {
  user: null,
  isLoggedIn: false,
};

const authSlice = createSlice({
  name: 'auth',
  initialState,
  reducers: {
    login(state, action: PayloadAction<{ id: string; name: string; email: string }>) {
      state.user = action.payload;
      state.isLoggedIn = true;
    },
    logout(state) {
      state.user = null;
      state.isLoggedIn = false;
    },
    setUser(state, action: PayloadAction<{ id: string; name: string; email: string } | null>) {
      state.user = action.payload;
      state.isLoggedIn = action.payload !== null;
    },
  },
});

export const { login, logout, setUser } = authSlice.actions;
export default authSlice.reducer;